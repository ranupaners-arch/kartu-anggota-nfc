import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/api/ids.dart';

void main() {
  test('login menyimpan token lalu scan mengirim Bearer dan body yang benar', () async {
    final seen = <http.Request>[];
    final api = ApiClient(
      baseUrl: 'http://srv/',
      client: MockClient((req) async {
        seen.add(req);
        if (req.url.path == '/api/auth/login') {
          return http.Response(jsonEncode({'token': 'T1', 'user': {'role': 'scanner'}}), 200);
        }
        return http.Response(
          jsonEncode({'status': 'ok', 'attendance_status': 'hadir', 'message': 'Berhasil.', 'member': {'name': 'Budi', 'member_no': '001'}}),
          200,
        );
      }),
    );

    await api.login('a@b.c', 'pw', deviceUuid: 'dev-1');
    final r = await api.scan(7, 'card:qr:abc', source: 'qr');

    expect(r.ok, isTrue);
    expect(r.memberName, 'Budi');
    final scan = seen.last;
    expect(scan.url.toString(), 'http://srv/api/events/7/scan');
    expect(scan.headers['Authorization'], 'Bearer T1');
    final body = jsonDecode(scan.body) as Map<String, dynamic>;
    expect(body['source'], 'qr');
    expect(body['client_uuid'], matches(RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')));
  });

  test('error server jadi ApiException dengan pesan', () async {
    final api = ApiClient(
      baseUrl: 'http://srv',
      client: MockClient((_) async => http.Response(jsonEncode({'message': 'Email atau password salah.'}), 422)),
    );
    expect(
      () => api.login('x', 'y', deviceUuid: 'd'),
      throwsA(isA<ApiException>().having((e) => e.message, 'message', contains('salah'))),
    );
  });

  test('uuidV4 unik dan berformat v4', () {
    final a = uuidV4(), b = uuidV4();
    expect(a, isNot(b));
    expect(a[14], '4');
  });
}
