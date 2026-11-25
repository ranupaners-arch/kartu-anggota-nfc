import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/member/loans_page.dart';
import 'package:material_ui/material_ui.dart';

final list = jsonEncode({
  'data': [
    {'id': 1, 'item': 'Proyektor ruang sidang utama beserta kabel', 'quantity': 2, 'due_on': '2026-11-20', 'overdue': true},
    {'id': 2, 'item': 'Tenda', 'quantity': 1, 'due_on': '2026-12-20', 'overdue': false},
  ],
});

void main() {
  Future<void> pump(WidgetTester tester, http.Response res, {double scale = 1}) async {
    tester.view.physicalSize = scale == 1 ? const Size(432, 960) : const Size(320, 568);
    tester.view.devicePixelRatio = 1;
    tester.platformDispatcher.textScaleFactorTestValue = scale;
    addTearDown(tester.view.reset);
    addTearDown(tester.platformDispatcher.clearAllTestValues);
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(
          body: LoansPage(
            api: ApiClient(baseUrl: 'http://srv', client: MockClient((_) async => res)),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('daftar pinjaman & yang terlambat', (tester) async {
    await pump(tester, http.Response(list, 200));
    expect(find.textContaining('kabel (2)'), findsOneWidget);
    expect(find.text('Terlambat'), findsOneWidget);
    expect(find.text('Dipinjam'), findsOneWidget);
  });

  testWidgets('kosong & server lama', (tester) async {
    await pump(tester, http.Response('{"data":[]}', 200));
    expect(find.textContaining('tidak sedang meminjam'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
    await pump(tester, http.Response('{}', 404));
    expect(find.textContaining('belum mendukung inventaris'), findsOneWidget);
  });

  testWidgets('HP kecil dengan huruf besar tidak meluber', (tester) async {
    await pump(tester, http.Response(list, 200), scale: 2);
    expect(tester.takeException(), isNull);
  });
}
