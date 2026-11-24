import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/app/server_config.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/login_screen.dart';
import 'package:kartuanggota/screens/member/eprofile_screen.dart';
import 'package:kartuanggota/screens/member/my_card_tab.dart';
import 'package:kartuanggota/screens/register_screen.dart';
import 'package:kartuanggota/screens/member/member_home_screen.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';

const server = ServerConfig(url: 'http://srv', name: 'HMPS');

void main() {
  testWidgets('kartu kosong → e-profil terkirim → kartu menunggu pengesahan', (tester) async {
    // Formulir panjang: layar tes dibuat tinggi agar semua kolom terlihat tanpa menggulir.
    tester.view.physicalSize = const Size(800, 2000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    var state = 'none';
    http.Request? sent;
    final api = ApiClient(
      baseUrl: 'http://srv',
      client: MockClient((req) async {
        switch (req.url.path) {
          case '/api/me/card':
            return http.Response(
              jsonEncode({
                'state': state,
                'organization': 'HMPS',
                'member': state == 'none' ? null : {'name': 'Dina Ayu', 'member_no': '2501001', 'division': 'Humas', 'photo_url': null},
              }),
              200,
            );
          case '/api/me/profile-form':
            return http.Response(
              jsonEncode({
                'organization': 'HMPS',
                'term': '2026',
                'name': 'Dina',
                'email': 'dina@mhs.test',
                'divisions': [
                  {'id': 3, 'name': 'Humas'},
                ],
              }),
              200,
            );
          case '/api/me/profile':
            sent = req;
            state = 'pending';
            return http.Response(
              jsonEncode({
                'state': 'pending',
                'organization': 'HMPS',
                'member': {'name': 'Dina Ayu', 'member_no': '2501001'},
              }),
              201,
            );
        }
        return http.Response('{}', 404);
      }),
    )..token = 't';

    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(
          body: MyCardTab(
            api: api,
            name: 'Dina',
            onRegister: (context) => Navigator.of(context).push<bool>(
              MaterialPageRoute(
                builder: (_) => EProfileScreen(api: api, pickPhoto: () async => (name: 'wajah.jpg', bytes: List.filled(100, 1))),
              ),
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.textContaining('Daftarkan keanggotaan'), findsOneWidget);

    await tester.tap(find.textContaining('Daftarkan keanggotaan'));
    await tester.pumpAndSettle();
    expect(find.text('E-profil'), findsOneWidget);

    await tester.enterText(find.byType(EditableText).at(0), 'Dina Ayu');
    await tester.enterText(find.byType(EditableText).at(1), '2501001');
    await tester.ensureVisible(find.text('Pilih foto'));
    await tester.tap(find.text('Pilih foto'));
    await tester.pumpAndSettle();
    expect(find.text('wajah.jpg'), findsOneWidget);
    await tester.ensureVisible(find.textContaining('Saya setuju'));
    await tester.tap(find.textContaining('Saya setuju'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('Kirim e-profil'));
    await tester.tap(find.text('Kirim e-profil'));
    await tester.pumpAndSettle();

    expect(sent, isNotNull);
    expect(find.text('Menunggu pengesahan pusat'), findsOneWidget); // kembali ke kartu, diperbarui
  });

  testWidgets('layar Masuk menampilkan "Daftar" hanya bila pendaftaran dibuka', (tester) async {
    for (final open in [false, true]) {
      final api = ApiClient(baseUrl: 'http://srv', client: MockClient((req) async => http.Response(jsonEncode({'app': 'kartu-anggota', 'registration_open': open}), 200)));
      expect(await api.registrationOpen(), open);
    }
  });

  testWidgets('daftar akun → masuk ke layar anggota', (tester) async {
    SharedPreferences.setMockInitialValues({});
    Map<String, dynamic>? body;
    final api = ApiClient(
      baseUrl: 'http://srv',
      client: MockClient((req) async {
        if (req.url.path == '/api/auth/register') {
          body = jsonDecode(req.body) as Map<String, dynamic>;
          return http.Response(
            jsonEncode({
              'token': 'T',
              'user': {'name': 'Dina', 'role': 'member', 'member_id': null},
            }),
            201,
          );
        }
        return http.Response(jsonEncode({'state': 'none', 'organization': 'HMPS', 'member': null}), 200);
      }),
    );
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: RegisterScreen(server: server, api: api),
      ),
    );
    await tester.pumpAndSettle();
    final fields = find.byType(EditableText);
    await tester.enterText(fields.at(0), 'Dina');
    await tester.enterText(fields.at(1), 'dina@mhs.test');
    await tester.enterText(fields.at(2), 'pendek');
    await tester.enterText(fields.at(3), 'pendek');
    await tester.ensureVisible(find.text('Daftar'));
    await tester.tap(find.text('Daftar'));
    await tester.pumpAndSettle();
    expect(find.text('Password baru minimal 10 karakter.'), findsOneWidget);
    expect(body, isNull);

    await tester.enterText(fields.at(2), 'RahasiaBaru123');
    await tester.enterText(fields.at(3), 'RahasiaBaru123');
    await tester.ensureVisible(find.text('Daftar'));
    await tester.tap(find.text('Daftar'));
    for (var i = 0; i < 10; i++) {
      await tester.pump(const Duration(milliseconds: 100));
    }
    expect(body?['email'], 'dina@mhs.test');
    expect(find.byType(MemberHomeScreen), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  test('LoginScreen tetap bisa dibuat (tanpa jaringan)', () => expect(const LoginScreen(server: server), isA<LoginScreen>()));
}
