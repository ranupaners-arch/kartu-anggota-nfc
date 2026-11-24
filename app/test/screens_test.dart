import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/app/server_config.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/connect_screen.dart';
import 'package:kartuanggota/screens/login_screen.dart';
import 'package:kartuanggota/widgets/scan_result_card.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  group('alamat server', () {
    test('normalisasi menambah http:// dan membuang garis miring', () {
      expect(ServerAddress.normalize(' 192.0.2.10:8000/ '), 'http://192.0.2.10:8000');
      expect(ServerAddress.normalize('https://absen.org.id'), 'https://absen.org.id');
    });

    test('build uji boleh http, build rilis wajib https', () {
      expect(ServerAddress.check('http://10.0.0.1:8000', release: false), isNull);
      expect(ServerAddress.check('http://10.0.0.1:8000', release: true), contains('https://'));
      expect(ServerAddress.check('https://absen.org.id', release: true), isNull);
      expect(ServerAddress.check('ftp://x', release: false), isNotNull);
    });

    test('QR penghubung dari dashboard dan URL biasa', () {
      final q = ServerAddress.parseQr('kartuanggota://connect?server=http%3A%2F%2F192.0.2.10%3A8000&name=HMPS')!;
      expect(q.url, 'http://192.0.2.10:8000');
      expect(q.name, 'HMPS');
      expect(ServerAddress.parseQr('https://absen.org.id/')!.url, 'https://absen.org.id');
      expect(ServerAddress.parseQr('card:qr:abc'), isNull); // QR anggota, bukan QR penghubung
      expect(ServerAddress.parseQr('kartuanggota://connect'), isNull);
    });

    test('verifikasi server lewat /api/instance', () async {
      MockClient reply(int code, Object body) => MockClient((_) async => http.Response(jsonEncode(body), code));

      final ok = await ServerAddress.verify('http://srv', client: reply(200, {'app': 'kartu-anggota', 'name': 'HMPS', 'api_version': 1}));
      expect(ok.name, 'HMPS');
      expect(ok.url, 'http://srv');

      expect(() => ServerAddress.verify('http://srv', client: reply(200, {'app': 'lain'})), throwsA(isA<ServerConnectException>()));
      expect(() => ServerAddress.verify('http://srv', client: reply(404, {'message': 'x'})), throwsA(isA<ServerConnectException>()));
      expect(
        () => ServerAddress.verify('http://srv', client: reply(200, {'app': 'kartu-anggota', 'api_version': 0})),
        throwsA(isA<ServerConnectException>().having((e) => e.message, 'message', contains('terlalu lama'))),
      );
    });
  });

  testWidgets('belum terhubung → layar Hubungkan; setelah terhubung → Login dengan nama organisasi', (tester) async {
    SharedPreferences.setMockInitialValues({});
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: ConnectScreen(
          verify: (url) async => ServerConfig(url: url, name: 'HMPS'),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Hubungkan ke Organisasi'), findsOneWidget);

    await tester.enterText(find.byType(EditableText), '192.0.2.10:8000');
    await tester.tap(find.text('Hubungkan'));
    await tester.pumpAndSettle();
    expect(find.text('Organisasi: HMPS'), findsOneWidget);
    expect(find.text('Organisasi: HMPS'), findsOneWidget);
    expect(find.text('Ganti organisasi'), findsOneWidget);
    expect((await SharedPreferences.getInstance()).getString('server_url'), 'http://192.0.2.10:8000');
  });

  testWidgets('server bukan Kartu Anggota ditolak dengan pesan jelas', (tester) async {
    SharedPreferences.setMockInitialValues({});
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: ConnectScreen(verify: (_) async => throw ServerConnectException('Alamat ini bukan server Kartu Anggota.')),
      ),
    );
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(EditableText), 'contoh.com');
    await tester.tap(find.text('Hubungkan'));
    await tester.pumpAndSettle();
    expect(find.text('Alamat ini bukan server Kartu Anggota.'), findsOneWidget);
    expect(find.textContaining('Organisasi:'), findsNothing);
  });

  // Alur awal sejak layar sambutan: splash → sambutan → Masuk → Hubungkan/Masuk (lihat start_flow_test.dart).
  testWidgets('app pertama kali dibuka (tanpa server): sambutan → Masuk → layar Hubungkan', (tester) async {
    SharedPreferences.setMockInitialValues({});
    await tester.pumpWidget(const KartuAnggotaApp());
    await tester.pumpAndSettle();
    await tester.tap(find.text('Masuk'));
    await tester.pumpAndSettle();
    expect(find.text('Hubungkan ke Organisasi'), findsOneWidget);
  });

  testWidgets('server tersimpan: sambutan → Masuk → layar Masuk organisasi itu', (tester) async {
    SharedPreferences.setMockInitialValues({'server_url': 'http://srv', 'server_name': 'HMPS'});
    await tester.pumpWidget(const KartuAnggotaApp());
    await tester.pumpAndSettle();
    await tester.tap(find.text('Masuk'));
    await tester.pumpAndSettle();
    expect(find.text('Organisasi: HMPS'), findsOneWidget);
  });

  testWidgets('kartu hasil scan menampilkan label sesuai status', (tester) async {
    Future<void> show(ScanResult r) => tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(body: ScanResultCard(result: r)),
      ),
    );

    await show(const ScanResult(status: 'ok', attendanceStatus: 'hadir', message: 'Berhasil.', memberName: 'Budi', memberNo: '001'));
    expect(find.text('Hadir'), findsOneWidget);
    expect(find.text('Budi'), findsOneWidget);
    await show(const ScanResult(status: 'ok', attendanceStatus: 'telat', message: 'Berhasil.', memberName: 'Budi'));
    expect(find.text('Telat'), findsOneWidget);
    await show(const ScanResult(status: 'duplicate', message: 'Sudah absen.', memberName: 'Budi'));
    expect(find.text('Sudah absen'), findsOneWidget);
    await show(const ScanResult(status: 'blocked', message: 'Kartu diblokir.'));
    expect(find.text('Kartu diblokir'), findsWidgets);
  });

  test('LoginScreen membutuhkan server', () {
    expect(
      const LoginScreen(
        server: ServerConfig(url: 'http://srv', name: 'X'),
      ).server.name,
      'X',
    );
  });
}
