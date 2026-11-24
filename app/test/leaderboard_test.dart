import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/member/leaderboard_page.dart';
import 'package:kartuanggota/screens/member/my_history_tab.dart';
import 'package:material_ui/material_ui.dart';

import 'badges_test.dart' show achievementsJson;

Map<String, dynamic> board({bool optedIn = false, bool active = true}) => {
  'opted_in': optedIn,
  'me': active ? {'points': 58, 'rank': 3, 'total': 40} : null,
  'top': [
    {'rank': 1, 'name': 'Anggota Dengan Nama Sangat Panjang Sekali', 'division': 'Hubungan Masyarakat', 'points': 90, 'is_me': false},
    if (optedIn) {'rank': 2, 'name': 'Budi', 'division': null, 'points': 58, 'is_me': true},
  ],
};

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
          body: LeaderboardPage(
            api: ApiClient(baseUrl: 'http://srv', client: MockClient(f)),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('bawaan tersembunyi; tampilkan lalu sembunyikan', (tester) async {
    final sent = <bool>[];
    await pump(tester, (r) async {
      var opted = false;
      if (r.method == 'PUT') sent.add(opted = (jsonDecode(r.body) as Map<String, dynamic>)['opt_in'] as bool);
      return http.Response(jsonEncode(board(optedIn: opted)), 200);
    });
    expect(find.text('3 dari 40 anggota aktif · 58 poin'), findsOneWidget);
    expect(find.text('Budi (Anda)'), findsNothing);
    await tester.tap(find.text('Tampilkan nama saya'));
    await tester.pumpAndSettle();
    expect(find.text('Budi (Anda)'), findsOneWidget);
    await tester.tap(find.text('Sembunyikan nama saya'));
    await tester.pumpAndSettle();
    expect(sent, [true, false]);
    expect(find.text('Budi (Anda)'), findsNothing);
  });

  testWidgets('gagal mengubah: pilihan lama tetap & pesan tampil', (tester) async {
    await pump(tester, (r) async => r.method == 'PUT' ? throw http.ClientException('putus') : http.Response(jsonEncode(board()), 200));
    await tester.tap(find.text('Tampilkan nama saya'));
    await tester.pumpAndSettle();
    expect(find.textContaining('tidak terjangkau'), findsOneWidget);
    expect(find.text('Tampilkan nama saya'), findsOneWidget);
  });

  testWidgets('server lama & anggota tidak aktif', (tester) async {
    await pump(tester, (_) async => http.Response('{}', 404));
    expect(find.textContaining('belum mendukung'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
    await pump(tester, (_) async => http.Response(jsonEncode(board(active: false)), 200));
    expect(find.textContaining('anggota aktif periode ini'), findsOneWidget);
    expect(find.text('Tampilkan nama saya'), findsNothing);
  });

  testWidgets('HP kecil dengan huruf besar tidak meluber', (tester) async {
    await pump(tester, (_) async => http.Response(jsonEncode(board(optedIn: true)), 200), scale: 2);
    expect(tester.takeException(), isNull);
  });

  testWidgets('dibuka dari Riwayat', (tester) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);
    final api = ApiClient(
      baseUrl: 'http://srv',
      client: MockClient(
        (r) async => http.Response(
          jsonEncode(
            r.url.path.endsWith('achievements')
                ? achievementsJson
                : r.url.path.endsWith('leaderboard')
                ? board()
                : {
                    'by_status': {'hadir': 1},
                    'total': 1,
                    'attendance_rate': 100,
                    'recent': <Object>[],
                  },
          ),
          200,
        ),
      ),
    );
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(body: MyHistoryTab(api: api)),
      ),
    );
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('Papan peringkat'));
    await tester.tap(find.text('Papan peringkat'));
    await tester.pumpAndSettle();
    expect(find.byType(LeaderboardPage), findsOneWidget);
    expect(find.text('Peringkat Anda'), findsOneWidget);
  });
}
