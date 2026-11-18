import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import 'roster.dart';

/// Scan yang belum terkirim ke server. client_uuid membuat pengiriman ulang tidak menggandakan data.
class PendingScan {
  const PendingScan({required this.clientUuid, required this.eventId, required this.payload, required this.source, required this.scannedAt, this.sessionId});

  factory PendingScan.fromJson(Map<String, dynamic> j) => PendingScan(
        clientUuid: j['client_uuid'] as String,
        eventId: j['event_id'] as int,
        payload: j['payload'] as String,
        source: j['source'] as String,
        scannedAt: DateTime.parse(j['scanned_at'] as String),
        sessionId: j['session_id'] as int?,
      );

  final String clientUuid, payload, source;
  final int eventId;
  final DateTime scannedAt;

  /// Sesi yang dipilih saat scan (null: server memilih menurut waktu scan).
  final int? sessionId;

  Map<String, dynamic> toJson() => {
        'client_uuid': clientUuid, 'event_id': eventId, 'payload': payload, 'source': source,
        'scanned_at': scannedAt.toUtc().toIso8601String(), 'session_id': ?sessionId,
      };

  /// Bentuk satu item untuk POST /api/events/{id}/scan/batch.
  Map<String, dynamic> toBatchItem() => {
        'payload': payload, 'source': source, 'client_uuid': clientUuid,
        'scanned_at': scannedAt.toUtc().toIso8601String(), 'session_id': ?sessionId,
      };
}

/// Penyimpanan lokal: roster per kegiatan dan antrean scan offline.
class OfflineStore {
  OfflineStore(this._prefs);

  final SharedPreferences _prefs;
  static const _queueKey = 'offline_queue';
  static String _rosterKey(int eventId) => 'roster:$eventId';

  static Future<OfflineStore> open() async => OfflineStore(await SharedPreferences.getInstance());

  Future<void> saveRoster(Roster r) => _prefs.setString(_rosterKey(r.eventId), jsonEncode(r.toApiJson()));

  Roster? loadRoster(int eventId) {
    final raw = _prefs.getString(_rosterKey(eventId));
    if (raw == null) return null;
    try {
      return Roster.fromApi(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      return null; // data rusak/format lama: anggap tidak ada
    }
  }

  List<PendingScan> pending({int? eventId}) {
    final raw = _prefs.getString(_queueKey);
    if (raw == null) return [];
    final all = [for (final j in jsonDecode(raw) as List) PendingScan.fromJson(j as Map<String, dynamic>)];
    return eventId == null ? all : all.where((p) => p.eventId == eventId).toList();
  }

  Future<void> enqueue(PendingScan scan) async {
    final all = pending()..removeWhere((p) => p.clientUuid == scan.clientUuid);
    await _write([...all, scan]);
  }

  Future<void> remove(Iterable<String> clientUuids) async {
    final drop = clientUuids.toSet();
    await _write(pending().where((p) => !drop.contains(p.clientUuid)).toList());
  }

  Future<void> _write(List<PendingScan> list) => _prefs.setString(_queueKey, jsonEncode([for (final p in list) p.toJson()]));
}
