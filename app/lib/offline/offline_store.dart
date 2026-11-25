import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_client.dart';
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

  Map<String, dynamic> toJson() => {'client_uuid': clientUuid, 'event_id': eventId, 'payload': payload, 'source': source, 'scanned_at': scannedAt.toUtc().toIso8601String(), 'session_id': ?sessionId};

  /// Bentuk satu item untuk POST /api/events/{id}/scan/batch.
  Map<String, dynamic> toBatchItem() => {'payload': payload, 'source': source, 'client_uuid': clientUuid, 'scanned_at': scannedAt.toUtc().toIso8601String(), 'session_id': ?sessionId};
}

/// Scan offline yang ditolak server, agar petugas tahu siapa yang tidak tercatat. Isi kartu tidak disimpan.
class RejectedScan {
  const RejectedScan({required this.clientUuid, required this.eventId, required this.status, required this.message, required this.scannedAt, this.eventTitle, this.memberName, this.memberNo});

  factory RejectedScan.fromJson(Map<String, dynamic> j) => RejectedScan(
    clientUuid: j['client_uuid'] as String,
    eventId: j['event_id'] as int,
    status: j['status'] as String,
    message: j['message'] as String,
    scannedAt: DateTime.parse(j['scanned_at'] as String),
    eventTitle: j['event_title'] as String?,
    memberName: j['member_name'] as String?,
    memberNo: j['member_no'] as String?,
  );

  final String clientUuid, status, message;
  final int eventId;
  final DateTime scannedAt;
  final String? eventTitle, memberName, memberNo;

  Map<String, dynamic> toJson() => {
    'client_uuid': clientUuid,
    'event_id': eventId,
    'status': status,
    'message': message,
    'scanned_at': scannedAt.toUtc().toIso8601String(),
    'event_title': ?eventTitle,
    'member_name': ?memberName,
    'member_no': ?memberNo,
  };
}

/// Penyimpanan lokal: roster per kegiatan dan antrean scan offline, terpisah per [owner] (server + akun).
/// Tanpa owner: kunci versi app lama; isinya dipindahkan ke akun pertama yang membuka penyimpanan.
class OfflineStore {
  OfflineStore(this._prefs, {this.owner});

  final SharedPreferences _prefs;
  final String? owner;
  static const _queueBase = 'offline_queue';
  static const eventsKey = 'offline_events';
  static const _rejectedBase = 'offline_rejected';

  String _key(String base) => owner == null ? base : '$base@$owner';
  String get _queueKey => _key(_queueBase);
  String get _rejectedKey => _key(_rejectedBase);
  String _rosterKey(int eventId) => _key('roster:$eventId');

  static Future<OfflineStore> open({String? owner}) async {
    final store = OfflineStore(await SharedPreferences.getInstance(), owner: owner);
    if (owner != null) await store._adoptLegacy();
    return store;
  }

  /// Pindahkan data versi app lama ke akun ini: tulis dulu, baru hapus yang lama.
  Future<void> _adoptLegacy() async {
    final old = OfflineStore(_prefs);
    // Tanpa await di antara baca, tulis & hapus: scan yang masuk bersamaan tidak boleh ikut terhapus.
    final ids = {for (final p in pending()) p.clientUuid}, known = {for (final r in rejected()) r.clientUuid};
    final queue = old.pending().where((p) => !ids.contains(p.clientUuid)).toList();
    final refused = old.rejected().where((r) => !known.contains(r.clientUuid)).toList();
    await Future.wait([
      if (queue.isNotEmpty) _write([...pending(), ...queue]),
      _prefs.remove(_queueBase),
      if (refused.isNotEmpty) _writeRejected([...rejected(), ...refused]),
      _prefs.remove(_rejectedBase),
    ]);
    for (final k in _prefs.getKeys().where((k) => k == eventsKey || (k.startsWith('roster:') && !k.contains('@'))).toList()) {
      final value = _prefs.getString(k);
      if (value != null && !_prefs.containsKey(_key(k))) await _prefs.setString(_key(k), value);
      await _prefs.remove(k);
    }
  }

  /// Jumlah scan tertunda milik akun/server lain di HP ini (tanpa isi: hanya untuk pemberitahuan).
  int othersPending() {
    var n = 0;
    for (final k in _prefs.getKeys().where((k) => k.startsWith('$_queueBase@') && k != _queueKey)) {
      try {
        n += (jsonDecode(_prefs.getString(k) ?? '[]') as List).length;
      } catch (_) {
        // data rusak: abaikan
      }
    }
    return n;
  }

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

  /// Daftar kegiatan terakhir dari server, agar layar Scan bisa dibuka tanpa internet.
  Future<void> saveEvents(List<EventSummary> events) => _prefs.setString(_key(eventsKey), jsonEncode([for (final e in events) e.toJson()]));

  /// Kegiatan tersimpan yang check-in-nya belum ditutup (termasuk yang belum dibuka).
  List<EventSummary> loadEvents(DateTime now) {
    final raw = _prefs.getString(_key(eventsKey));
    if (raw == null) return [];
    try {
      return [for (final j in jsonDecode(raw) as List) EventSummary.fromJson(j as Map<String, dynamic>)].where((e) => e.isOngoingAt(now)).toList();
    } catch (_) {
      return []; // data rusak/format lama: anggap tidak ada
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

  List<RejectedScan> rejected() {
    final raw = _prefs.getString(_rejectedKey);
    if (raw == null) return [];
    try {
      return [for (final j in jsonDecode(raw) as List) RejectedScan.fromJson(j as Map<String, dynamic>)];
    } catch (_) {
      return []; // data rusak/format lama: anggap tidak ada
    }
  }

  Future<void> addRejected(List<RejectedScan> items) async {
    if (items.isEmpty) return;
    final ids = {for (final r in items) r.clientUuid};
    await _writeRejected([...rejected().where((r) => !ids.contains(r.clientUuid)), ...items]);
  }

  Future<void> removeRejected(String clientUuid) => _writeRejected(rejected().where((r) => r.clientUuid != clientUuid).toList());

  Future<void> _writeRejected(List<RejectedScan> list) => _prefs.setString(_rejectedKey, jsonEncode([for (final r in list) r.toJson()]));

  Future<void> _write(List<PendingScan> list) => _prefs.setString(_queueKey, jsonEncode([for (final p in list) p.toJson()]));
}
