import 'dart:convert';

import 'package:http/http.dart' as http;

import 'ids.dart';

class ApiException implements Exception {
  ApiException(this.status, this.message);
  final int status;
  final String message;
  @override
  String toString() => 'HTTP $status: $message';
}

class EventSummary {
  const EventSummary(this.id, this.title);
  final int id;
  final String title;
}

/// Hasil scan dari server: status ok|duplicate|blocked|invalid|not_target|outside_window|clock_drift.
class ScanResult {
  const ScanResult({required this.status, required this.message, this.attendanceStatus, this.memberName, this.memberNo});

  factory ScanResult.fromJson(Map<String, dynamic> j) {
    final m = j['member'] as Map<String, dynamic>?;
    return ScanResult(
      status: j['status'] as String,
      message: j['message'] as String? ?? '',
      attendanceStatus: j['attendance_status'] as String?,
      memberName: m?['name'] as String?,
      memberNo: m?['member_no'] as String?,
    );
  }

  final String status;
  final String message;
  final String? attendanceStatus;
  final String? memberName;
  final String? memberNo;

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

  Future<Map<String, dynamic>> login(String email, String password, {required String deviceUuid, String deviceName = 'HP Petugas'}) async {
    final j = await _send('POST', '/api/auth/login', {
      'email': email,
      'password': password,
      'device_uuid': deviceUuid,
      'device_name': deviceName,
    });
    token = j['token'] as String;
    return j['user'] as Map<String, dynamic>;
  }

  Future<List<EventSummary>> ongoingEvents() async {
    final j = await _send('GET', '/api/events?status=ongoing');
    return [for (final e in j['data'] as List) EventSummary(e['id'] as int, e['title'] as String)];
  }

  Future<ScanResult> scan(int eventId, String payload, {String source = 'nfc'}) async {
    final j = await _send('POST', '/api/events/$eventId/scan', {
      'payload': payload,
      'source': source,
      'client_uuid': uuidV4(),
      'scanned_at': DateTime.now().toUtc().toIso8601String(),
    });
    return ScanResult.fromJson(j);
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
      throw ApiException(res.statusCode, '$msg');
    }
    return decoded as Map<String, dynamic>;
  }
}
