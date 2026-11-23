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

/// Pesan untuk pengguna: pakai pesan server bila ada, selain itu berdasarkan kode HTTP.
String errorMessage(int status, String? serverMessage) {
  if (serverMessage != null && serverMessage.trim().isNotEmpty) return serverMessage;
  return switch (status) {
    401 => 'Sesi berakhir. Silakan masuk lagi.',
    403 => 'Anda tidak punya akses untuk tindakan ini.',
    404 => 'Data tidak ditemukan.',
    413 => 'Berkas terlalu besar.',
    419 || 422 => 'Data yang dikirim tidak valid.',
    429 => 'Terlalu banyak percobaan. Coba lagi sebentar lagi.',
    502 || 503 || 504 => 'Server sedang tidak tersedia. Coba lagi beberapa saat lagi.',
    >= 500 => 'Terjadi kesalahan di server. Coba lagi nanti.',
    _ => 'Permintaan gagal (kode $status).',
  };
}

/// Satu sesi kegiatan dengan jendela check-in-nya sendiri. Tiap sesi = satu kehadiran.
class SessionInfo {
  const SessionInfo({required this.id, required this.title, required this.opensAt, required this.lateAfter, required this.closesAt});

  factory SessionInfo.fromJson(Map<String, dynamic> j) => SessionInfo(
    id: j['id'] as int,
    title: j['title'] as String,
    opensAt: DateTime.parse(j['checkin_opens_at'] as String),
    lateAfter: DateTime.parse(j['late_after'] as String),
    closesAt: DateTime.parse(j['checkin_closes_at'] as String),
  );

  final int id;
  final String title;
  final DateTime opensAt, lateAfter, closesAt;

  bool isOpenAt(DateTime t) => !t.isBefore(opensAt) && !t.isAfter(closesAt);

  Map<String, dynamic> toJson() => {
    'id': id,
    'title': title,
    'checkin_opens_at': opensAt.toUtc().toIso8601String(),
    'late_after': lateAfter.toUtc().toIso8601String(),
    'checkin_closes_at': closesAt.toUtc().toIso8601String(),
  };

  /// Daftar sesi dari JSON; sesi tanpa jendela (server lama) dilewati.
  static List<SessionInfo> listFrom(Object? raw) => [
    for (final s in (raw as List?) ?? const [])
      if ((s as Map<String, dynamic>)['checkin_opens_at'] != null) SessionInfo.fromJson(s),
  ];
}

class EventSummary {
  const EventSummary(this.id, this.title, {this.location, this.checkinClosesAt, this.sessions = const [], this.currentSessionId});

  factory EventSummary.fromJson(Map<String, dynamic> e) => EventSummary(
    e['id'] as int,
    e['title'] as String,
    location: e['location'] as String?,
    checkinClosesAt: DateTime.tryParse(e['checkin_closes_at'] as String? ?? '')?.toLocal(),
    sessions: SessionInfo.listFrom(e['sessions']),
    currentSessionId: e['current_session_id'] as int?,
  );

  final int id;
  final String title;
  final String? location;
  final DateTime? checkinClosesAt;

  /// Urut sesi utama dulu. Lebih dari satu → petugas memilih sesi di layar Scan.
  final List<SessionInfo> sessions;

  /// Sesi yang sedang membuka check-in menurut server (saat daftar dimuat).
  final int? currentSessionId;
}

/// Hasil scan dari server: status ok|duplicate|blocked|invalid|not_target|outside_window|clock_drift.
class ScanResult {
  const ScanResult({required this.status, required this.message, this.attendanceStatus, this.memberId, this.memberName, this.memberNo, this.division, this.photoUrl, this.offline = false});

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
  ApiClient({required String baseUrl, http.Client? client}) : baseUrl = baseUrl.replaceAll(RegExp(r'/+$'), ''), _http = client ?? http.Client();

  final String baseUrl;
  final http.Client _http;
  String? token;

  Future<Map<String, dynamic>> login(String email, String password, {required String deviceUuid, String deviceName = 'HP Petugas', String? otp}) async {
    final j = await _send('POST', '/api/auth/login', {'email': email, 'password': password, 'device_uuid': deviceUuid, 'device_name': deviceName, if (otp != null && otp.isNotEmpty) 'otp': otp});
    token = j['token'] as String;
    return j['user'] as Map<String, dynamic>;
  }

  /// Lupa password: server mengirim tautan atur ulang ke email (bila email server sudah diatur).
  /// Mengembalikan pesan untuk ditampilkan; ApiException (mis. 503 belum aktif) berisi pesan server.
  Future<String> forgotPassword(String email) async {
    final j = await _send('POST', '/api/auth/forgot', {'email': email});
    return j['message'] as String? ?? 'Tautan dikirim bila email terdaftar.';
  }

  /// Data akun yang sedang masuk (nama, peran, tautan anggota) — dipakai app untuk cek ulang peran.
  Future<Map<String, dynamic>> me() => _send('GET', '/api/me');

  /// Ganti password (wajib bila akun masih memakai password sementara dari pengurus).
  Future<void> changePassword(String current, String next) async {
    await _send('PUT', '/api/me/password', {'current_password': current, 'password': next, 'password_confirmation': next});
  }

  /// Apakah server membuka pendaftaran akun dari app (tombol "Daftar"). Gagal → anggap tertutup.
  Future<bool> registrationOpen() async {
    try {
      final j = await _send('GET', '/api/instance');
      return j['registration_open'] == true;
    } catch (_) {
      return false;
    }
  }

  /// Daftar akun anggota baru (lalu langsung masuk). Mengembalikan data pengguna.
  Future<Map<String, dynamic>> register({required String name, required String email, required String password, required String deviceUuid, String deviceName = 'HP Anggota'}) async {
    final j = await _send('POST', '/api/auth/register', {
      'name': name, 'email': email, 'password': password, 'password_confirmation': password, 'device_uuid': deviceUuid, 'device_name': deviceName,
    });
    token = j['token'] as String;
    return j['user'] as Map<String, dynamic>;
  }

  /// Daftar kartu NFC sendiri — langkah 1: siapkan kartu (payload + password proteksi).
  Future<CardInit> selfCardInit() async => CardInit.fromJson(await _send('POST', '/api/me/card/init', {}));

  /// Langkah 2 (setelah kartu tertulis): aktifkan; kartu lama diblokir server.
  Future<MemberCardInfo> selfCardAssign(int cardId, {String? hwUid}) async =>
      MemberCardInfo.fromJson(await _send('POST', '/api/me/card/$cardId/assign', {'hw_uid': ?hwUid}));

  /// Ajukan kartu pengganti (hilang/rusak). "hilang" langsung memblokir kartu aktif.
  Future<MemberCardInfo> requestCardReplacement(String reason, String? note) async =>
      MemberCardInfo.fromJson(await _send('POST', '/api/me/card/replacement', {'reason': reason, if (note != null && note.isNotEmpty) 'note': note}));

  /// Data formulir e-profil: organisasi, periode, pilihan divisi.
  Future<({String organization, String? term, String name, String email, List<({int id, String name})> divisions})> profileForm() async {
    final j = await _send('GET', '/api/me/profile-form');
    return (
      organization: j['organization'] as String? ?? '',
      term: j['term'] as String?,
      name: j['name'] as String? ?? '',
      email: j['email'] as String? ?? '',
      divisions: [for (final d in j['divisions'] as List) (id: d['id'] as int, name: d['name'] as String)],
    );
  }

  /// Kirim e-profil (foto opsional) → kartu menunggu pengesahan.
  /// Status perubahan data diri (data saat ini, pengajuan menunggu, keputusan terakhir).
  Future<ProfileChangeStatus> profileChange() async => ProfileChangeStatus.fromJson(await _send('GET', '/api/me/profile-change'));

  /// Ajukan perubahan nama / no. HP / foto; berlaku setelah disetujui Super Admin.
  Future<ProfileChangeStatus> requestProfileChange({String? name, String? phone, String? reason, List<int>? photoBytes, String? photoName}) async {
    final req = http.MultipartRequest('POST', Uri.parse('$baseUrl/api/me/profile-change'))
      ..headers.addAll({'Accept': 'application/json', if (token != null) 'Authorization': 'Bearer $token'})
      ..fields.addAll({
        if (name != null && name.isNotEmpty) 'name': name,
        if (phone != null && phone.isNotEmpty) 'phone': phone,
        if (reason != null && reason.isNotEmpty) 'reason': reason,
      });
    if (photoBytes != null && photoName != null) {
      req.files.add(http.MultipartFile.fromBytes('photo', photoBytes, filename: photoName));
    }
    final res = await http.Response.fromStream(await _http.send(req).timeout(const Duration(seconds: 30)));
    Object? decoded;
    try {
      decoded = res.body.isEmpty ? null : jsonDecode(res.body);
    } on FormatException {
      decoded = null;
    }
    if (res.statusCode >= 400) {
      final errors = decoded is Map ? decoded['errors'] : null;
      final msg = errors is Map && errors.isNotEmpty ? (errors.values.first as List).first as String? : (decoded is Map ? decoded['message'] as String? : null);
      throw ApiException(res.statusCode, errorMessage(res.statusCode, msg));
    }
    return ProfileChangeStatus.fromJson(decoded as Map<String, dynamic>);
  }

  Future<MemberCardInfo> submitProfile({required String name, required String memberNo, String? phone, String? cohort, int? divisionId, List<int>? photoBytes, String? photoName}) async {
    final req = http.MultipartRequest('POST', Uri.parse('$baseUrl/api/me/profile'))
      ..headers.addAll({'Accept': 'application/json', if (token != null) 'Authorization': 'Bearer $token'})
      ..fields.addAll({
        'name': name, 'member_no': memberNo, 'consent': '1',
        if (phone != null && phone.isNotEmpty) 'phone': phone,
        if (cohort != null && cohort.isNotEmpty) 'cohort': cohort,
        if (divisionId != null) 'division_id': '$divisionId',
      });
    if (photoBytes != null && photoName != null) {
      req.files.add(http.MultipartFile.fromBytes('photo', photoBytes, filename: photoName));
    }
    final res = await http.Response.fromStream(await _http.send(req).timeout(const Duration(seconds: 30)));
    Object? decoded;
    try {
      decoded = res.body.isEmpty ? null : jsonDecode(res.body);
    } on FormatException {
      decoded = null;
    }
    if (res.statusCode >= 400) {
      final errors = decoded is Map ? decoded['errors'] : null;
      final msg = errors is Map && errors.isNotEmpty ? (errors.values.first as List).first as String? : (decoded is Map ? decoded['message'] as String? : null);
      throw ApiException(res.statusCode, errorMessage(res.statusCode, msg));
    }
    return MemberCardInfo.fromJson(decoded as Map<String, dynamic>);
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

  Future<ScanResult> scan(int eventId, String payload, {String source = 'nfc', String? clientUuid, DateTime? scannedAt, int? sessionId}) async {
    final j = await _send('POST', '/api/events/$eventId/scan', {
      'payload': payload,
      'source': source,
      'client_uuid': clientUuid ?? uuidV4(),
      'scanned_at': (scannedAt ?? DateTime.now()).toUtc().toIso8601String(),
      'session_id': ?sessionId,
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

  /// Pengumuman pengurus yang berlaku untuk anggota ini (disematkan dulu).
  Future<List<Announcement>> myAnnouncements() async {
    final j = await _send('GET', '/api/me/announcements');
    return [for (final a in j['data'] as List) Announcement.fromJson(a as Map<String, dynamic>)];
  }

  /// Kotak notifikasi anggota (pengumuman, keputusan izin/kartu, denda, pengingat), terbaru dulu.
  Future<List<MemberNotice>> myNotifications() async {
    final j = await _send('GET', '/api/me/notifications');
    return [for (final n in j['data'] as List) MemberNotice.fromJson(n as Map<String, dynamic>)];
  }

  /// Tautan langganan jadwal (.ics) milik akun ini; [reset] = buat tautan baru (yang lama mati).
  Future<String> calendarUrl({bool reset = false}) async {
    final j = await _send(reset ? 'POST' : 'GET', reset ? '/api/me/calendar/reset' : '/api/me/calendar');
    return j['url'] as String;
  }

  /// Tampilan kartu anggota: status e-profil/pengesahan + data kartu.
  Future<MemberCardInfo> myCard() async => MemberCardInfo.fromJson(await _send('GET', '/api/me/card'));

  /// Riwayat kartu anggota sendiri: semua kartu & laporan kartu.
  Future<CardHistory> myCardHistory() async => CardHistory.fromJson(await _send('GET', '/api/me/card/history'));

  /// Cek kartu NFC yang ditempel ke HP: mine | mine_inactive | other | unknown | invalid.
  Future<String> checkMyCard(String payload) async => (await _send('POST', '/api/me/card/check', {'payload': payload}))['result'] as String;

  /// Poin & lencana keaktifan periode berjalan.
  Future<Achievements> myAchievements() async => Achievements.fromJson(await _send('GET', '/api/me/achievements'));

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
      Object? decoded;
      try {
        decoded = res.body.isEmpty ? null : jsonDecode(res.body);
      } on FormatException {
        decoded = null;
      }
      final errors = decoded is Map ? decoded['errors'] : null;
      final msg = errors is Map && errors.isNotEmpty ? (errors.values.first as List).first as String? : (decoded is Map ? decoded['message'] as String? : null);
      throw ApiException(res.statusCode, errorMessage(res.statusCode, msg));
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
  /// Unduh berkas ber-auth (mis. foto anggota) sebagai bytes.
  Future<List<int>> downloadBytes(String url) async {
    final res = await _http.get(Uri.parse(url), headers: {if (token != null) 'Authorization': 'Bearer $token'}).timeout(const Duration(seconds: 15));
    if (res.statusCode != 200) throw ApiException(res.statusCode, errorMessage(res.statusCode, null));
    return res.bodyBytes;
  }

  Future<List<Map<String, dynamic>>> scanBatch(int eventId, List<Map<String, dynamic>> scans) async {
    final j = await _send('POST', '/api/events/$eventId/scan/batch', {'scans': scans});
    return [for (final r in j['results'] as List) r as Map<String, dynamic>];
  }

  Future<Map<String, dynamic>> _send(String method, String path, [Map<String, dynamic>? body]) async {
    final req = http.Request(method, Uri.parse('$baseUrl$path'))
      ..headers.addAll({'Accept': 'application/json', if (body != null) 'Content-Type': 'application/json', if (token != null) 'Authorization': 'Bearer $token'});
    if (body != null) req.body = jsonEncode(body);

    final res = await http.Response.fromStream(await _http.send(req).timeout(const Duration(seconds: 10)));
    Object? decoded;
    try {
      decoded = res.body.isEmpty ? <String, dynamic>{} : jsonDecode(res.body);
    } on FormatException {
      decoded = null; // bukan JSON (mis. halaman error proxy)
    }
    if (res.statusCode >= 400) {
      throw ApiException(res.statusCode, errorMessage(res.statusCode, decoded is Map ? decoded['message'] as String? : null), otpRequired: decoded is Map && decoded['otp_required'] == true);
    }
    return decoded as Map<String, dynamic>;
  }
}

class MemberHit {
  const MemberHit({required this.id, required this.memberNo, required this.name, this.division, this.hasActiveCard = false});

  factory MemberHit.fromJson(Map<String, dynamic> j) {
    final ms = (j['memberships'] as List?)?.cast<Map<String, dynamic>>() ?? const [];
    final division = ms.isEmpty ? null : (ms.first['division'] as Map<String, dynamic>?)?['name'] as String?;
    return MemberHit(id: j['id'] as int, memberNo: j['member_no'] as String, name: j['name'] as String, division: division, hasActiveCard: j['active_card'] != null);
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
    required this.id,
    required this.title,
    required this.startsAt,
    required this.checkinClosesAt,
    this.location,
    this.closed = false,
    this.mandatory = true,
    this.myStatus,
    this.excuseStatus,
    this.excuseType,
    this.excuseNote,
    this.canRequestExcuse = false,
    this.sessions = 1,
    this.presentSessions = 0,
    this.endsAt,
    this.checkinOpensAt,
    this.lateAfter,
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
      sessions: j['sessions'] as int? ?? 1,
      presentSessions: j['present_sessions'] as int? ?? 0,
      endsAt: _time(j['ends_at']),
      checkinOpensAt: _time(j['checkin_opens_at']),
      lateAfter: _time(j['late_after']),
    );
  }

  final int id;
  final String title;
  final String? location;
  final DateTime startsAt, checkinClosesAt;
  final bool closed, mandatory, canRequestExcuse;
  final String? myStatus, excuseStatus, excuseType, excuseNote;

  /// Kegiatan multi-sesi: jumlah sesi & sesi yang dihadiri (tiap sesi = satu kehadiran).
  final int sessions, presentSessions;

  /// Jadwal lengkap untuk halaman detail (opsional: server lama tidak mengirimnya).
  final DateTime? endsAt, checkinOpensAt, lateAfter;

  static DateTime? _time(Object? v) => v is String ? DateTime.tryParse(v)?.toLocal() : null;
}

/// Ringkasan kehadiran anggota (GET /api/me/history).
class MemberHistory {
  const MemberHistory({required this.byStatus, required this.total, this.term, this.rate, this.recent = const [], this.atRisk = false, this.alpaStreak = 0, this.finesUnpaid = 0, this.fines = const [], this.monthly = const []});

  factory MemberHistory.fromJson(Map<String, dynamic> j) => MemberHistory(
    term: j['term'] as String?,
    byStatus: {for (final e in (j['by_status'] as Map<String, dynamic>).entries) e.key: (e.value as num).toInt()},
    total: (j['total'] as num).toInt(),
    rate: (j['attendance_rate'] as num?)?.toDouble(),
    atRisk: (j['warning'] as Map<String, dynamic>?)?['at_risk'] as bool? ?? false,
    alpaStreak: ((j['warning'] as Map<String, dynamic>?)?['alpa_streak'] as num?)?.toInt() ?? 0,
    recent: [
      for (final r in j['recent'] as List)
        (event: (r as Map<String, dynamic>)['event'] as String? ?? '-', date: DateTime.tryParse(r['date'] as String? ?? '')?.toLocal(), status: r['status'] as String),
    ],
    monthly: [
      for (final m in (j['monthly'] as List?) ?? const [])
        (
          month: DateTime.parse('${(m as Map<String, dynamic>)['month']}-01'),
          total: (m['total'] as num).toInt(),
          present: ((m['hadir'] as num?) ?? 0).toInt() + ((m['telat'] as num?) ?? 0).toInt(),
        ),
    ],
    finesUnpaid: ((j['fines'] as Map<String, dynamic>?)?['unpaid_total'] as num?)?.toInt() ?? 0,
    fines: [
      for (final f in ((j['fines'] as Map<String, dynamic>?)?['items'] as List?) ?? const [])
        (
          event: (f as Map<String, dynamic>)['event'] as String? ?? '-',
          date: DateTime.tryParse(f['date'] as String? ?? '')?.toLocal(),
          amount: (f['amount'] as num).toInt(),
          status: f['status'] as String,
        ),
    ],
  );

  final String? term;
  final Map<String, int> byStatus;
  final int total;
  final double? rate;

  /// Peringatan (aturan sama dengan "Perlu perhatian" di dashboard): Alpa berturut-turut / kehadiran rendah.
  final bool atRisk;
  final int alpaStreak;
  final List<({String event, DateTime? date, String status})> recent;

  /// Rekap per bulan (urut waktu): jumlah kegiatan & yang dihadiri (hadir + telat).
  final List<({DateTime month, int total, int present})> monthly;

  /// Denda absen pakai QR (kartu tertinggal) periode ini.
  final int finesUnpaid;
  final List<({String event, DateTime? date, int amount, String status})> fines;
}

/// "Rp7.500" (pemisah ribuan titik).
String rupiah(int n) => 'Rp${n.toString().replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => '.')}';

/// Pengumuman pengurus (GET /api/me/announcements).
class Announcement {
  const Announcement({required this.id, required this.title, required this.body, this.pinned = false, this.createdAt, this.division});

  factory Announcement.fromJson(Map<String, dynamic> j) => Announcement(
    id: j['id'] as int,
    title: j['title'] as String,
    body: j['body'] as String,
    pinned: j['pinned'] as bool? ?? false,
    createdAt: DateTime.tryParse(j['created_at'] as String? ?? '')?.toLocal(),
    division: j['division'] as String?,
  );

  final int id;
  final String title, body;
  final bool pinned;
  final DateTime? createdAt;
  final String? division;
}

/// Status kartu anggota di app: belum e-profil, menunggu pengesahan, aktif, atau tidak aktif.
enum CardState { none, pending, active, inactive }

class MemberCardInfo {
  MemberCardInfo({required this.state, this.status, this.organization, this.term, this.validUntil, this.name, this.memberNo, this.division, this.cohort, this.photoUrl, this.cardLast4, this.canWriteCard = false, this.canRequestCard = false, this.pendingRequestReason, this.lastDecision, this.cardIssuedAt, this.cardIssuedBy, this.selfWritesLeft = 0});

  final CardState state;
  final String? status, organization, term, name, memberNo, division, cohort, photoUrl, cardLast4;
  final DateTime? validUntil;

  /// Boleh mendaftarkan kartu NFC sendiri (jatah tersisa) / boleh mengajukan kartu pengganti.
  final bool canWriteCard, canRequestCard;

  /// Alasan permintaan kartu pengganti yang masih menunggu (hilang/rusak), bila ada.
  final String? pendingRequestReason;

  /// Keputusan pengurus atas laporan kartu terakhir (30 hari): status approved/rejected, tindak lanjut self/admin, catatan.
  final ({String reason, String status, String? resolution, String? note})? lastDecision;

  /// Kartu NFC aktif: tanggal terbit & cara terbit ("self" = didaftarkan sendiri, "admin" = pengurus).
  final DateTime? cardIssuedAt;
  final String? cardIssuedBy;

  /// Sisa jatah mendaftarkan kartu NFC sendiri.
  final int selfWritesLeft;

  factory MemberCardInfo.fromJson(Map<String, dynamic> j) {
    final m = j['member'] as Map<String, dynamic>?;
    final c = j['card'] as Map<String, dynamic>?;
    final sc = j['self_card'] as Map<String, dynamic>?;
    return MemberCardInfo(
      state: CardState.values.firstWhere((s) => s.name == j['state'], orElse: () => CardState.none),
      status: j['status'] as String?,
      organization: j['organization'] as String?,
      term: j['term'] as String?,
      validUntil: j['valid_until'] == null ? null : DateTime.tryParse(j['valid_until'] as String),
      name: m?['name'] as String?,
      memberNo: m?['member_no'] as String?,
      division: m?['division'] as String?,
      cohort: m?['cohort'] as String?,
      photoUrl: m?['photo_url'] as String?,
      cardLast4: c?['last4'] as String?,
      cardIssuedAt: DateTime.tryParse(c?['issued_at'] as String? ?? '')?.toLocal(),
      cardIssuedBy: c?['issued_by'] as String?,
      selfWritesLeft: (sc?['writes_left'] as num?)?.toInt() ?? 0,
      canWriteCard: sc?['can_write'] == true,
      canRequestCard: sc?['can_request'] == true,
      pendingRequestReason: (sc?['pending_request'] as Map<String, dynamic>?)?['reason'] as String?,
      lastDecision: switch (sc?['last_decision']) {
        final Map<String, dynamic> d => (reason: d['reason'] as String? ?? '', status: d['status'] as String? ?? '', resolution: d['resolution'] as String?, note: d['review_note'] as String?),
        _ => null,
      },
    );
  }
}

/// Satu notifikasi di kotak notifikasi anggota (GET /api/me/notifications).
class MemberNotice {
  const MemberNotice({required this.id, required this.type, required this.title, required this.at, this.body});

  factory MemberNotice.fromJson(Map<String, dynamic> j) => MemberNotice(
    id: j['id'] as String,
    type: j['type'] as String,
    title: j['title'] as String,
    body: j['body'] as String?,
    at: DateTime.parse(j['at'] as String).toLocal(),
  );

  final String id;

  /// announcement | excuse | card | fine | reminder
  final String type;
  final String title;
  final String? body;
  final DateTime at;
}

/// Riwayat kartu anggota (GET /api/me/card/history).
class CardHistory {
  const CardHistory({this.cards = const [], this.requests = const []});

  factory CardHistory.fromJson(Map<String, dynamic> j) => CardHistory(
    cards: [
      for (final c in (j['cards'] as List? ?? const []))
        (
          last4: (c as Map<String, dynamic>)['last4'] as String,
          status: c['status'] as String,
          issuedAt: DateTime.tryParse(c['issued_at'] as String? ?? '')?.toLocal(),
          revokedAt: DateTime.tryParse(c['revoked_at'] as String? ?? '')?.toLocal(),
          issuedBy: c['issued_by'] as String?,
        ),
    ],
    requests: [
      for (final r in (j['requests'] as List? ?? const []))
        (
          reason: (r as Map<String, dynamic>)['reason'] as String,
          status: r['status'] as String,
          note: r['note'] as String?,
          reviewNote: r['review_note'] as String?,
          resolution: r['resolution'] as String?,
          createdAt: DateTime.tryParse(r['created_at'] as String? ?? '')?.toLocal(),
        ),
    ],
  );

  final List<({String last4, String status, DateTime? issuedAt, DateTime? revokedAt, String? issuedBy})> cards;
  final List<({String reason, String status, String? note, String? reviewNote, String? resolution, DateTime? createdAt})> requests;
}

/// Status perubahan data diri anggota (GET/POST /api/me/profile-change).
class ProfileChangeStatus {
  const ProfileChangeStatus({required this.currentName, this.currentPhone, this.pending, this.lastDecision});

  factory ProfileChangeStatus.fromJson(Map<String, dynamic> j) {
    ({String? name, String? phone, bool photo, String status, String? note, DateTime? at})? parse(Object? v) => v is Map<String, dynamic>
        ? (
            name: v['name'] as String?,
            phone: v['phone'] as String?,
            photo: v['has_photo'] == true,
            status: v['status'] as String? ?? 'pending',
            note: v['review_note'] as String?,
            at: DateTime.tryParse((v['reviewed_at'] ?? v['created_at']) as String? ?? '')?.toLocal(),
          )
        : null;
    final cur = j['current'] as Map<String, dynamic>? ?? const {};
    return ProfileChangeStatus(currentName: cur['name'] as String? ?? '', currentPhone: cur['phone'] as String?, pending: parse(j['pending']), lastDecision: parse(j['last_decision']));
  }

  final String currentName;
  final String? currentPhone;

  /// Pengajuan yang masih menunggu persetujuan (isian yang diajukan saja).
  final ({String? name, String? phone, bool photo, String status, String? note, DateTime? at})? pending;

  /// Keputusan terakhir (30 hari): status approved/rejected + catatan pengurus.
  final ({String? name, String? phone, bool photo, String status, String? note, DateTime? at})? lastDecision;
}

/// Poin & lencana keaktifan anggota (GET /api/me/achievements).
class Achievements {
  const Achievements({this.points = 0, this.earned = 0, this.badges = const []});

  factory Achievements.fromJson(Map<String, dynamic> j) => Achievements(
    points: (j['points'] as num?)?.toInt() ?? 0,
    earned: (j['earned'] as num?)?.toInt() ?? 0,
    badges: [
      for (final b in (j['badges'] as List?) ?? const [])
        (
          key: (b as Map<String, dynamic>)['key'] as String,
          title: b['title'] as String,
          description: b['description'] as String? ?? '',
          earned: b['earned'] == true,
          progress: (b['progress'] as num?)?.toInt() ?? 0,
          target: (b['target'] as num?)?.toInt() ?? 1,
          hint: b['hint'] as String?,
          percent: b['unit'] == 'percent',
        ),
    ],
  );

  final int points, earned;
  final List<({String key, String title, String description, bool earned, int progress, int target, String? hint, bool percent})> badges;
}
