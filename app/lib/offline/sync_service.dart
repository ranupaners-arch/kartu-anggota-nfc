import '../api/api_client.dart';
import 'offline_store.dart';

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
        }
      }
      if (done.isEmpty) break; // server tidak memberi hasil per item: hindari loop tanpa akhir
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
}
