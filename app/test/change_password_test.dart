import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/app/server_config.dart';
import 'package:kartuanggota/app/session.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/change_password_screen.dart';
import 'package:kartuanggota/screens/member/member_home_screen.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  test('aturan password baru sama dengan server', () {
    expect(passwordProblem('pendek1', 'pendek1'), contains('minimal 10'));
    expect(passwordProblem('hanyahurufsaja', 'hanyahurufsaja'), contains('huruf dan angka'));
    expect(passwordProblem('Rahasia12345', 'Rahasia12346'), contains('belum sama'));
    expect(passwordProblem('Rahasia12345', 'Rahasia12345'), isNull);
  });

  testWidgets('password sementara: wajib diganti, sesi disimpan setelah berhasil', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final sent = <Map<String, dynamic>>[];
    var fail = true;
    final api = ApiClient(
      baseUrl: 'http://srv',
      client: MockClient((req) async {
        if (req.url.path == '/api/me/password') {
          sent.add(jsonDecode(req.body) as Map<String, dynamic>);
          if (fail) {
            fail = false;
            return http.Response(jsonEncode({'message': 'Password baru harus berbeda dari password sementara.'}), 422);
          }
          return http.Response(jsonEncode({'must_change_password': false}), 200);
        }
        return http.Response('{"data":[]}', 200);
      }),
    )..token = 'T';
    final session = Session(baseUrl: 'http://srv', token: 'T', userName: 'Ani', role: 'member');

    await tester.pumpWidget(
      KartuAnggotaApp(
        home: ChangePasswordScreen(api: api, session: session, currentPassword: 'Kq7m-Xt3a-Pw9e', server: const ServerConfig(url: 'http://srv', name: 'HMPS')),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Buat password baru'), findsOneWidget);

    final fields = find.byType(EditableText);
    // Validasi di app dulu: tidak dikirim ke server.
    await tester.enterText(fields.at(0), 'pendek');
    await tester.enterText(fields.at(1), 'pendek');
    await tester.ensureVisible(find.text('Simpan & lanjut'));
    await tester.tap(find.text('Simpan & lanjut'));
    await tester.pumpAndSettle();
    expect(find.text('Password baru minimal 10 karakter.'), findsOneWidget);
    expect(sent, isEmpty);

    // Ditolak server → pesan server tampil, sesi belum disimpan.
    await tester.enterText(fields.at(0), 'RahasiaBaru123');
    await tester.enterText(fields.at(1), 'RahasiaBaru123');
    await tester.ensureVisible(find.text('Simpan & lanjut'));
    await tester.tap(find.text('Simpan & lanjut'));
    await tester.pumpAndSettle();
    expect(find.text('Password baru harus berbeda dari password sementara.'), findsOneWidget);
    expect(await Session.load(), isNull);

    // Berhasil → sesi tersimpan, masuk ke layar anggota (tab QR punya penghitung waktu: jangan tunggu "settle").
    await tester.ensureVisible(find.text('Simpan & lanjut'));
    await tester.tap(find.text('Simpan & lanjut'));
    for (var i = 0; i < 10; i++) {
      await tester.pump(const Duration(milliseconds: 100));
    }
    expect(sent.last, {'current_password': 'Kq7m-Xt3a-Pw9e', 'password': 'RahasiaBaru123', 'password_confirmation': 'RahasiaBaru123'});
    expect((await Session.load())?.token, 'T');
    expect(find.byType(MemberHomeScreen), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });
}
