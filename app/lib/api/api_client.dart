import 'dart:convert';

import 'package:http/http.dart' as http;

import 'ids.dart';

class ApiException implements Exception {
  ApiException(this.status, this.message, {this.otpRequired = false});
  final int status;
  final String message;

  /// Server meminta kode 2FA (akun pengurus dengan 2FA aktif).
  final bool otpRequired;
  @override
  String toString() => 'HTTP $status: $message';
}

class EventSummary {
  const EventSummary(this.id, this.title, {this.location, this.checkinClosesAt});

  factory EventSummary.fromJson(Map<String, dynamic> e) => EventSummary(
        e['id'] as int,
        e['title'] as String,
        location: e['location'] as String?,
        checkinClosesAt: DateTime.tryParse(e['checkin_closes_at'] as String? ?? '')?.toLocal(),
      );

  final int id;
  final String title;
  final String? location;
  final DateTime? checkinClosesAt;
}

/// Hasil scan dari server: status ok|duplicate|blocked|invalid|not_target|outside_window|clock_drift.
class ScanResult {
  const ScanResult({
    required this.status,
    required this.message,
    this.attendanceStatus,
    this.memberId,
    this.memberName,
    this.memberNo,
    this.division,
    this.photoUrl,
    this.offline = false,
  });

  factory ScanResult.fromJson(Map<String, dynamic> j) {
    final m = j['member'] as Map<String, dynamic>?;
    return ScanResult(
      status: j['status'] as String,
      message: j['message'] as String? ?? '',
      attendanceStatus: j['attendance_status'] as String?,
      memberId: m?['id'] as int?,
      memberName: m?['name'] as String?,
      memberNo: m?['member_no'] as String?,
      division: m?['division'] as String?,
      photoUrl: m?['photo_url'] as String?,
    );
  }

  final String status;
  final String message;
  final String? attendanceStatus;
  final int? memberId;
  final String? memberName;
  final String? memberNo;
  final String? division;

  /// Foto anggota (butuh header Authorization) untuk verifikasi wajah oleh petugas.
  final String? photoUrl;

  /// Hasil diperiksa di perangkat (tanpa internet); keputusan akhir saat sinkron.
  final bool offline;

  bool get ok => status == 'ok';
}

/// Klien API minimal untuk prototipe.
class ApiClient {
  ApiClient({required String baseUrl, http.Client? client})
      : baseUrl = baseUrl.replaceAll(RegExp(r'/+$'), ''),
        _http = client ?? http.Client();

  final String baseUrl;
  final http.Client _http;
  String? token;

  Future<Map<String, dynamic>> login(String email, String password, {required String deviceUuid, String deviceName = 'HP Petugas', String? otp}) async {
    final j = await _send('POST', '/api/auth/login', {
      'email': email,
      'password': password,
      'device_uuid': deviceUuid,
      'device_name': deviceName,
      if (otp != null && otp.isNotEmpty) 'otp': otp,
    });
    token = j['token'] as String;
    return j['user'] as Map<String, dynamic>;
  }

  Future<void> logout() async {
    try {
      await _send('POST', '/api/auth/logout');
    } catch (_) {
      // Token mungkin sudah tidak berlaku; tetap keluar di perangkat.
    }
    token = null;
  }

  Future<List<EventSummary>> ongoingEvents() async {
    final j = await _send('GET', '/api/events?status=ongoing');
    return [for (final e in j['data'] as List) EventSummary.fromJson(e as Map<String, dynamic>)];
  }

  Future<ScanResult> scan(int eventId, String payload, {String source = 'nfc', String? clientUuid, DateTime? scannedAt}) async {
    final j = await _send('POST', '/api/events/$eventId/scan', {
      'payload': payload,
      'source': source,
      'client_uuid': clientUuid ?? uuidV4(),
      'scanned_at': (scannedAt ?? DateTime.now()).toUtc().toIso8601String(),
    });
    return ScanResult.fromJson(j);
  }

  // ── Anggota ────────────────────────────────────────────────────────────────

  /// QR pribadi anggota (berganti tiap menit di server).
  Future<({String payload, int expiresIn})> myQr() async {
    final j = await _send('GET', '/api/me/qr');
    return (payload: j['payload'] as String, expiresIn: j['expires_in'] as int? ?? 90);
  }

  Future<List<MemberEvent>> myEvents() async {
    final j = await _send('GET', '/api/me/events');
    return [for (final e in j['data'] as List) MemberEvent.fromJson(e as Map<String, dynamic>)];
  }

  Future<MemberHistory> myHistory() async => MemberHistory.fromJson(await _send('GET', '/api/me/history'));

  /// Ajukan izin/sakit; lampiran opsional (gambar/PDF, maks. 5 MB).
  Future<void> submitExcuse({required int eventId, required String type, required String reason, List<int>? fileBytes, String? fileName}) async {
    final req = http.MultipartRequest('POST', Uri.parse('$baseUrl/api/excuses'))
      ..headers.addAll({'Accept': 'application/json', if (token != null) 'Authorization': 'Bearer $token'})
      ..fields.addAll({'event_id': '$eventId', 'type': type, 'reason': reason});
    if (fileBytes != null && fileName != null) {
      req.files.add(http.MultipartFile.fromBytes('attachment', fileBytes, filename: fileName));
    }
    final res = await http.Response.fromStream(await _http.send(req).timeout(const Duration(seconds: 30)));
    if (res.statusCode >= 400) {
      final decoded = res.body.isEmpty ? null : jsonDecode(res.body);
      final errors = decoded is Map ? decoded['errors'] : null;
      final msg = errors is Map && errors.isNotEmpty ? (errors.values.first as List).first : (decoded is Map ? decoded['message'] : null);
      throw ApiException(res.statusCode, '${msg ?? res.reasonPhrase}');
    }
  }

  /// Cari anggota (Super Admin) untuk penerbitan kartu.
  Future<List<MemberHit>> searchMembers(String query) async {
    final j = await _send('GET', '/api/members?per_page=30&q=${Uri.encodeQueryComponent(query.trim())}');
    return [for (final m in j['data'] as List) MemberHit.fromJson(m as Map<String, dynamic>)];
  }

  /// Langkah 1 penerbitan: buat kartu kosong di server + payload & password NTAG.
  Future<CardInit> initCard() async => CardInit.fromJson(await _send('POST', '/api/cards/init', {}));

  /// Langkah 3 penerbitan (setelah kartu berhasil ditulis): tautkan ke anggota; kartu lama diblokir server.
  Future<void> assignCard(int cardId, {required int memberId, String? hwUid}) async {
    await _send('POST', '/api/cards/$cardId/assign', {'member_id': memberId, 'hw_uid': ?hwUid});
  }

  /// Data offline kegiatan: jendela waktu + peserta & sidik jari kartu.
  Future<Map<String, dynamic>> roster(int eventId) => _send('GET', '/api/events/$eventId/roster');

  /// Kirim antrean offline; hasil per item memuat client_uuid.
  Future<List<Map<String, dynamic>>> scanBatch(int eventId, List<Map<String, dynamic>> scans) async {
    final j = await _send('POST', '/api/events/$eventId/scan/batch', {'scans': scans});
    return [for (final r in j['results'] as List) r as Map<String, dynamic>];
  }

  Future<Map<String, dynamic>> _send(String method, String path, [Map<String, dynamic>? body]) async {
    final req = http.Request(method, Uri.parse('$baseUrl$path'))
      ..headers.addAll({
        'Accept': 'application/json',
        if (body != null) 'Content-Type': 'application/json',
        if (token != null) 'Authorization': 'Bearer $token',
      });
    if (body != null) req.body = jsonEncode(body);

    final res = await http.Response.fromStream(await _http.send(req).timeout(const Duration(seconds: 10)));
    final decoded = res.body.isEmpty ? <String, dynamic>{} : jsonDecode(res.body);
    if (res.statusCode >= 400) {
      final msg = decoded is Map ? (decoded['message'] ?? res.reasonPhrase) : res.reasonPhrase;
      throw ApiException(res.statusCode, '$msg', otpRequired: decoded is Map && decoded['otp_required'] == true);
    }
    return decoded as Map<String, dynamic>;
  }
}

class MemberHit {
  const MemberHit({required this.id, required this.memberNo, required this.name, this.division, this.hasActiveCard = false});

  factory MemberHit.fromJson(Map<String, dynamic> j) {
    final ms = (j['memberships'] as List?)?.cast<Map<String, dynamic>>() ?? const [];
    final division = ms.isEmpty ? null : (ms.first['division'] as Map<String, dynamic>?)?['name'] as String?;
    return MemberHit(
      id: j['id'] as int, memberNo: j['member_no'] as String, name: j['name'] as String,
      division: division, hasActiveCard: j['active_card'] != null,
    );
  }

  final int id;
  final String memberNo, name;
  final String? division;
  final bool hasActiveCard;
}

class CardInit {
  const CardInit({required this.id, required this.payload, required this.pwd, required this.pack});

  factory CardInit.fromJson(Map<String, dynamic> j) {
    final ntag = j['ntag'] as Map<String, dynamic>?;
    if (ntag == null) throw ApiException(500, 'Server belum menyiapkan kunci kartu (CARD_KEY).');
    return CardInit(id: j['id'] as int, payload: j['payload'] as String, pwd: ntag['pwd'] as String, pack: ntag['pack'] as String);
  }

  final int id;
  final String payload, pwd, pack;
}

/// Kegiatan yang diikuti anggota (GET /api/me/events).
class MemberEvent {
  const MemberEvent({
    required this.id, required this.title, required this.startsAt, required this.checkinClosesAt,
    this.location, this.closed = false, this.mandatory = true, this.myStatus, this.excuseStatus, this.excuseType,
    this.excuseNote, this.canRequestExcuse = false,
  });

  factory MemberEvent.fromJson(Map<String, dynamic> j) {
    final x = j['excuse'] as Map<String, dynamic>?;
    return MemberEvent(
      id: j['id'] as int,
      title: j['title'] as String,
      location: j['location'] as String?,
      startsAt: DateTime.parse(j['starts_at'] as String).toLocal(),
      checkinClosesAt: DateTime.parse(j['checkin_closes_at'] as String).toLocal(),
      closed: j['closed_at'] != null,
      mandatory: j['is_mandatory'] as bool? ?? true,
      myStatus: j['my_status'] as String?,
      excuseStatus: x?['status'] as String?,
      excuseType: x?['type'] as String?,
      excuseNote: x?['review_note'] as String?,
      canRequestExcuse: j['can_request_excuse'] as bool? ?? false,
    );
  }

  final int id;
  final String title;
  final String? location;
  final DateTime startsAt, checkinClosesAt;
  final bool closed, mandatory, canRequestExcuse;
  final String? myStatus, excuseStatus, excuseType, excuseNote;
}

/// Ringkasan kehadiran anggota (GET /api/me/history).
class MemberHistory {
  const MemberHistory({required this.byStatus, required this.total, this.term, this.rate, this.recent = const []});

  factory MemberHistory.fromJson(Map<String, dynamic> j) => MemberHistory(
        term: j['term'] as String?,
        byStatus: {for (final e in (j['by_status'] as Map<String, dynamic>).entries) e.key: (e.value as num).toInt()},
        total: (j['total'] as num).toInt(),
        rate: (j['attendance_rate'] as num?)?.toDouble(),
        recent: [
          for (final r in j['recent'] as List)
            (event: (r as Map<String, dynamic>)['event'] as String? ?? '-', date: DateTime.tryParse(r['date'] as String? ?? '')?.toLocal(), status: r['status'] as String),
        ],
      );

  final String? term;
  final Map<String, int> byStatus;
  final int total;
  final double? rate;
  final List<({String event, DateTime? date, String status})> recent;
}
