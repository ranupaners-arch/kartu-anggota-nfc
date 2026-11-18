import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';

void main() {
  test('login dengan 2FA: server minta kode, lalu kode dikirim', () async {
    final bodies = <Map<String, dynamic>>[];
    final api = ApiClient(baseUrl: 'http://srv', client: MockClient((req) async {
      final b = jsonDecode(req.body) as Map<String, dynamic>;
      bodies.add(b);
      if (b['otp'] == null) {
        return http.Response(jsonEncode({'message': 'Masukkan kode 2FA dari aplikasi authenticator.', 'otp_required': true}), 422);
      }
      return http.Response(jsonEncode({'token': 'T', 'user': {'name': 'Admin', 'role': 'super_admin'}}), 200);
    }));

    await expectLater(api.login('a@b.c', 'pw', deviceUuid: 'd'), throwsA(isA<ApiException>().having((e) => e.otpRequired, 'otpRequired', true)));
    final user = await api.login('a@b.c', 'pw', deviceUuid: 'd', otp: '123456');
    expect((user['role'], api.token, bodies.last['otp'], bodies.first.containsKey('otp')), ('super_admin', 'T', '123456', false));
  });

  test('error biasa tidak meminta kode 2FA', () async {
    final api = ApiClient(baseUrl: 'http://srv', client: MockClient((_) async => http.Response(jsonEncode({'message': 'Email atau password salah.'}), 422)));
    await expectLater(api.login('a@b.c', 'x', deviceUuid: 'd'), throwsA(isA<ApiException>().having((e) => e.otpRequired, 'otpRequired', false)));
  });
}
