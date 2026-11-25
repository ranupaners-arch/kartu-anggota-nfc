import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/member/certificates_page.dart';
import 'package:material_ui/material_ui.dart';

final list = jsonEncode({
  'data': [
    {'id': 5, 'event': 'Seminar Nasional Teknologi dan Pengembangan Organisasi', 'number': '003/HMPS/1/2026', 'date': '2026-11-25T02:00:00Z'},
  ],
});

void main() {
  Future<List<List<int>>> pump(WidgetTester tester, Future<http.Response> Function(http.Request) f, {double scale = 1}) async {
    tester.view.physicalSize = scale == 1 ? const Size(432, 960) : const Size(320, 568);
    tester.view.devicePixelRatio = 1;
    tester.platformDispatcher.textScaleFactorTestValue = scale;
    addTearDown(tester.view.reset);
    addTearDown(tester.platformDispatcher.clearAllTestValues);
    final opened = <List<int>>[];
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(
          body: CertificatesPage(
            api: ApiClient(baseUrl: 'http://srv', client: MockClient(f)),
            openPdf: (b) async {
              opened.add(b);
              return null;
            },
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    return opened;
  }

  testWidgets('daftar & unduh PDF sendiri', (tester) async {
    final opened = await pump(tester, (r) async => r.url.path == '/api/me/certificates/5/pdf' ? http.Response.bytes([37, 80, 68, 70], 200) : http.Response(list, 200));
    expect(find.textContaining('003/HMPS/1/2026'), findsOneWidget);
    await tester.tap(find.text('Unduh PDF'));
    await tester.pumpAndSettle();
    expect(opened, [
      [37, 80, 68, 70],
    ]);
  });

  testWidgets('dicabut setelah daftar dimuat: pesan jelas', (tester) async {
    await pump(tester, (r) async => r.url.path.endsWith('/pdf') ? http.Response('{}', 410) : http.Response(list, 200));
    await tester.tap(find.text('Unduh PDF'));
    await tester.pumpAndSettle();
    expect(find.text('Sertifikat ini sudah dicabut pengurus.'), findsOneWidget);
  });

  testWidgets('kosong & server lama', (tester) async {
    await pump(tester, (_) async => http.Response('{"data":[]}', 200));
    expect(find.textContaining('Belum ada sertifikat'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
    await pump(tester, (_) async => http.Response('{}', 404));
    expect(find.textContaining('belum mendukung sertifikat'), findsOneWidget);
  });

  testWidgets('HP kecil dengan huruf besar tidak meluber', (tester) async {
    await pump(tester, (_) async => http.Response(list, 200), scale: 2);
    expect(tester.takeException(), isNull);
  });
}
