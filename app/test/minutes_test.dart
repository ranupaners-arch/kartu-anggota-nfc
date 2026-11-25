import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/member/minutes_page.dart';
import 'package:material_ui/material_ui.dart';

final list = jsonEncode({
  'data': [
    {
      'event_id': 3,
      'title': 'Rapat Kerja Tahunan Pengurus dan Perwakilan Divisi',
      'date': '2026-11-25T02:00:00Z',
      'content': 'Anggaran disetujui.',
      'documents': [
        {'id': 8, 'title': 'Materi presentasi lengkap dan lampiran anggaran', 'size': 1500000, 'file_name': 'materi.pptx'},
      ],
    },
  ],
});

void main() {
  Future<List<String>> pump(WidgetTester tester, Future<http.Response> Function(http.Request) f, {double scale = 1}) async {
    tester.view.physicalSize = scale == 1 ? const Size(432, 960) : const Size(320, 568);
    tester.view.devicePixelRatio = 1;
    tester.platformDispatcher.textScaleFactorTestValue = scale;
    addTearDown(tester.view.reset);
    addTearDown(tester.platformDispatcher.clearAllTestValues);
    final opened = <String>[];
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(
          body: MinutesPage(
            api: ApiClient(baseUrl: 'http://srv', client: MockClient(f)),
            openFile: (b, n) async {
              opened.add('$n ${b.length}');
              return null;
            },
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    return opened;
  }

  testWidgets('baca notulen & unduh dokumen', (tester) async {
    final opened = await pump(tester, (r) async => r.url.path == '/api/me/documents/8' ? http.Response.bytes([1, 2, 3], 200) : http.Response(list, 200));
    expect(find.text('Anggaran disetujui.'), findsNothing);
    await tester.tap(find.text('Baca notulen'));
    await tester.pumpAndSettle();
    expect(find.text('Anggaran disetujui.'), findsOneWidget);
    await tester.tap(find.textContaining('1,4 MB'));
    await tester.pumpAndSettle();
    expect(opened, ['materi.pptx 3']);
  });

  testWidgets('dokumen ditarik: pesan jelas', (tester) async {
    await pump(tester, (r) async => r.url.path.contains('documents') ? http.Response('{}', 404) : http.Response(list, 200));
    await tester.tap(find.textContaining('1,4 MB'));
    await tester.pumpAndSettle();
    expect(find.text('Dokumen ini sudah tidak tersedia.'), findsOneWidget);
  });

  testWidgets('kosong & server lama', (tester) async {
    await pump(tester, (_) async => http.Response('{"data":[]}', 200));
    expect(find.textContaining('Belum ada notulen'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
    await pump(tester, (_) async => http.Response('{}', 404));
    expect(find.textContaining('belum mendukung notulen'), findsOneWidget);
  });

  testWidgets('HP kecil dengan huruf besar tidak meluber', (tester) async {
    await pump(tester, (_) async => http.Response(list, 200), scale: 2);
    expect(tester.takeException(), isNull);
  });
}
