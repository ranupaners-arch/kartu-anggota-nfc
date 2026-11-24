import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/member/my_history_tab.dart';
import 'package:material_ui/material_ui.dart';

void main() {
  Future<void> pump(WidgetTester tester, Map<String, dynamic>? dues, {Size size = const Size(432, 1600), double scale = 1}) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1;
    tester.platformDispatcher.textScaleFactorTestValue = scale;
    addTearDown(tester.view.reset);
    addTearDown(tester.platformDispatcher.clearAllTestValues);
    final api = ApiClient(
      baseUrl: 'http://srv',
      client: MockClient(
        (r) async => r.url.path == '/api/me/history'
            ? http.Response(
                jsonEncode({
                  'by_status': {'hadir': 1},
                  'total': 1,
                  'attendance_rate': 100,
                  'recent': <Object>[],
                  'dues': ?dues,
                }),
                200,
              )
            : http.Response('{}', 404),
      ),
    );
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(body: MyHistoryTab(api: api)),
      ),
    );
    await tester.pumpAndSettle();
  }

  final bill = {'id': 1, 'title': 'Iuran November 2026 Untuk Kegiatan Besar', 'amount': 20000, 'status': 'unpaid', 'due_on': '2026-11-30'};

  testWidgets('tagihan & total belum lunas tampil di Riwayat', (tester) async {
    await pump(tester, {
      'unpaid_total': 20000,
      'items': [
        bill,
        {...bill, 'title': 'Iuran Oktober', 'status': 'paid', 'due_on': null},
      ],
    });
    expect(find.textContaining('Belum lunas: Rp20.000'), findsOneWidget);
    expect(find.textContaining('jatuh tempo 30 Nov 2026'), findsOneWidget);
    expect(find.text('Iuran Oktober'), findsOneWidget);
    expect(find.text('Lunas'), findsOneWidget);
  });

  testWidgets('semua lunas; server lama tanpa iuran → disembunyikan', (tester) async {
    await pump(tester, {
      'unpaid_total': 0,
      'items': [
        {...bill, 'status': 'paid'},
      ],
    });
    expect(find.text('Semua iuran sudah beres.'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
    await pump(tester, null);
    expect(find.text('Iuran'), findsNothing);
  });

  testWidgets('HP kecil dengan huruf besar tidak meluber', (tester) async {
    await pump(
      tester,
      {
        'unpaid_total': 20000,
        'items': [bill],
      },
      size: const Size(320, 3000),
      scale: 2,
    );
    expect(tester.takeException(), isNull);
  });
}
