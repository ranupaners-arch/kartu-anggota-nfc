import '../api/api_client.dart';
import '../nfc/card_payload.dart';
import 'offline_store.dart';
import 'roster.dart';

class SyncReport {
  const SyncReport({this.sent = 0, this.accepted = 0, this.duplicates = 0, this.rejected = 0, this.remaining = 0, this.error, this.stop = false});

  final int sent, accepted, duplicates, rejected, remaining;

  /// Diisi bila pengiriman terhenti (mis. jaringan); item tetap di antrean.
  final String? error;

  /// Percuma mencoba kegiatan lain sekarang (tanpa koneksi, sesi berakhir, dibatasi server).
  final bool stop;

  bool get ok => error == null;
}

/// Kirim antrean scan offline lewat batch (idempoten berkat client_uuid).
/// Item hanya dihapus dari antrean setelah server memberi hasil untuk item itu.
class SyncService {
  SyncService(this._api, this._store);

  final ApiClient _api;
  final OfflineStore _store;
  static const batchSize = 200;

  /// Kirim antrean semua kegiatan, termasuk yang check-in-nya sudah tutup.
  Future<SyncReport> syncAll() async {
    var sent = 0, accepted = 0, duplicates = 0, rejected = 0;
    String? error;
    for (final id in {for (final p in _store.pending()) p.eventId}) {
      final r = await sync(id);
      sent += r.sent;
      accepted += r.accepted;
      duplicates += r.duplicates;
      rejected += r.rejected;
      error ??= r.error;
      if (r.stop) break;
    }
    return SyncReport(sent: sent, accepted: accepted, duplicates: duplicates, rejected: rejected, remaining: _store.pending().length, error: error);
  }

  Future<SyncReport> sync(int eventId) async {
    var sent = 0, accepted = 0, duplicates = 0, rejected = 0;
    while (true) {
      final chunk = _store.pending(eventId: eventId).take(batchSize).toList();
      if (chunk.isEmpty) break;
      final List<Map<String, dynamic>> results;
      try {
        results = await _api.scanBatch(eventId, [for (final p in chunk) p.toBatchItem()]);
      } on ApiException catch (e) {
        return SyncReport(
          sent: sent,
          accepted: accepted,
          duplicates: duplicates,
          rejected: rejected,
          remaining: _store.pending(eventId: eventId).length,
          error: e.message,
          stop: e.unreachable || e.status == 401 || e.status == 429,
        );
      } catch (_) {
        return SyncReport(
          sent: sent,
          accepted: accepted,
          duplicates: duplicates,
          rejected: rejected,
          remaining: _store.pending(eventId: eventId).length,
          error: 'Belum terhubung ke server.',
          stop: true,
        );
      }

      final done = <String>[];
      final refused = <RejectedScan>[];
      final byUuid = {for (final p in chunk) p.clientUuid: p};
      final roster = _store.loadRoster(eventId);
      for (final r in results) {
        final uuid = r['client_uuid'] as String?;
        if (uuid == null) continue;
        done.add(uuid);
        switch (r['status']) {
          case 'ok':
            accepted++;
          case 'duplicate':
            duplicates++;
          default:
            rejected++;
            final p = byUuid[uuid];
            if (p == null) continue;
            refused.add(_refusal(p, r, roster));
        }
      }
      if (done.isEmpty) break; // server tidak memberi hasil per item: hindari loop tanpa akhir
      await _store.addRejected(refused); // simpan dulu, baru hapus dari antrean
      await _store.remove(done);
      sent += done.length;
    }
    return SyncReport(
      sent: sent,
      accepted: accepted,
      duplicates: duplicates,
      rejected: rejected,
      remaining: _store.pending(eventId: eventId).length,
    );
  }

  RejectedScan _refusal(PendingScan p, Map<String, dynamic> r, Roster? roster) {
    final m = r['member'] as Map<String, dynamic>?;
    final local = m == null ? roster?.byFingerprint(CardPayload.fingerprint(p.payload))?.member : null;
    return RejectedScan(
      clientUuid: p.clientUuid,
      eventId: p.eventId,
      eventTitle: roster?.title,
      memberName: m?['name'] as String? ?? local?.name,
      memberNo: m?['member_no'] as String? ?? local?.memberNo,
      status: r['status'] as String? ?? 'error',
      message: r['message'] as String? ?? '',
      scannedAt: p.scannedAt,
    );
  }

  /// Sesi ditolak server (401): bila perangkat ini dicabut, titipkan antrean untuk ditinjau pengurus.
  /// retry = true: koneksi/server bermasalah, token jangan dibuang dulu agar bisa dicoba lagi.
  Future<({int held, bool retry})> uploadHeld() async {
    var held = 0;
    while (true) {
      final chunk = _store.pending().take(batchSize).toList();
      if (chunk.isEmpty) return (held: held, retry: false);
      final List<Map<String, dynamic>> results;
      try {
        results = await _api.heldScans([
          for (final p in chunk) {...p.toBatchItem(), 'event_id': p.eventId},
        ]);
      } on ApiException catch (e) {
        return (held: held, retry: e.unreachable || e.status == 429); // 403/404: bukan perangkat dicabut / server lama
      } catch (_) {
        return (held: held, retry: true);
      }
      final byUuid = {for (final p in chunk) p.clientUuid: p};
      final done = <String>[];
      final refused = <RejectedScan>[];
      for (final r in results) {
        final p = byUuid[r['client_uuid']];
        if (p == null) continue;
        done.add(p.clientUuid);
        r['status'] == 'held' ? held++ : refused.add(_refusal(p, r, _store.loadRoster(p.eventId)));
      }
      if (done.isEmpty) return (held: held, retry: false);
      await _store.addRejected(refused);
      await _store.remove(done);
    }
  }
}
