import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/member/surveys_page.dart';
import 'package:material_ui/material_ui.dart';

final list = jsonEncode({
  'data': [
    {
      'id': 4,
      'event': 'Seminar Nasional Teknologi dan Pengembangan Organisasi',
      'date': '2026-11-25T02:00:00Z',
      'closes_at': '2026-12-01T02:00:00Z',
      'questions': ['Materi mudah dipahami oleh seluruh peserta kegiatan'],
    },
  ],
});

void main() {
  Future<void> pump(WidgetTester tester, Future<http.Response> Function(http.Request) f, {double scale = 1}) async {
    tester.view.physicalSize = scale == 1 ? const Size(432, 960) : const Size(320, 568);
    tester.view.devicePixelRatio = 1;
    tester.platformDispatcher.textScaleFactorTestValue = scale;
    addTearDown(tester.view.reset);
    addTearDown(tester.platformDispatcher.clearAllTestValues);
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(
          body: SurveysPage(
            api: ApiClient(baseUrl: 'http://srv', client: MockClient(f)),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> fill(WidgetTester tester) async {
    await tester.tap(find.byKey(const ValueKey('nilai-0-5')));
    await tester.tap(find.byKey(const ValueKey('nilai-1-3')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Kirim jawaban'));
    await tester.pumpAndSettle();
  }

  testWidgets('semua nilai wajib, lalu terkirim sekali', (tester) async {
    final sent = <Object?>[];
    await pump(tester, (r) async {
      if (r.method == 'POST') {
        sent.add(jsonDecode(r.body));
        return http.Response('{"ok":true}', 200);
      }
      return http.Response(list, 200);
    });
    await tester.tap(find.text('Kirim jawaban'), warnIfMissed: false);
    await tester.pumpAndSettle();
    expect(sent, isEmpty);
    await fill(tester);
    expect(sent, [
      {
        'rating': 5,
        'answers': [3],
        'comment': null,
      },
    ]);
    expect(find.text('Terima kasih'), findsOneWidget);
    expect(find.text('Kirim jawaban'), findsNothing);
  });

  testWidgets('ditolak server: pesan tampil, isian tetap', (tester) async {
    await pump(tester, (r) async => r.method == 'POST' ? http.Response('{"message":"Survei sudah ditutup."}', 422) : http.Response(list, 200));
    await fill(tester);
    expect(find.text('Survei sudah ditutup.'), findsOneWidget);
    expect(find.byIcon(Icons.star), findsNWidgets(8));
  });

  testWidgets('kosong & server lama', (tester) async {
    await pump(tester, (_) async => http.Response('{"data":[]}', 200));
    expect(find.textContaining('Tidak ada survei'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
    await pump(tester, (_) async => http.Response('{}', 404));
    expect(find.textContaining('belum mendukung survei'), findsOneWidget);
  });

  testWidgets('HP kecil dengan huruf besar tidak meluber', (tester) async {
    await pump(tester, (_) async => http.Response(list, 200), scale: 2);
    expect(tester.takeException(), isNull);
  });
}
