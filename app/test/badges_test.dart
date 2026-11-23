import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/member/my_history_tab.dart';
import 'package:kartuanggota/widgets/badges.dart';
import 'package:material_ui/material_ui.dart';

final achievementsJson = {
  'points': 58,
  'earned': 2,
  'badges': [
    {'key': 'first', 'title': 'Langkah Pertama', 'description': 'Hadir di kegiatan pertama periode ini.', 'earned': true, 'progress': 1, 'target': 1, 'hint': null},
    {'key': 'on_time_5', 'title': 'Tepat Waktu', 'description': 'Hadir tepat waktu 5× berturut-turut.', 'earned': true, 'progress': 0, 'target': 5, 'hint': null},
    {'key': 'streak_10', 'title': 'Beruntun', 'description': 'Hadir 10 kegiatan berturut-turut.', 'earned': false, 'progress': 3, 'target': 10, 'hint': null},
    {'key': 'diligent', 'title': 'Rajin', 'description': 'Kehadiran minimal 90%.', 'earned': false, 'progress': 85, 'target': 90, 'hint': null, 'unit': 'percent'},
    {'key': 'no_alpa', 'title': 'Tanpa Alpa', 'description': 'Tidak pernah Alpa.', 'earned': false, 'progress': 5, 'target': 5, 'hint': 'Tercatat Alpa 1× periode ini'},
    {'key': 'card_loyal', 'title': 'Kartu Setia', 'description': 'Selalu absen dengan kartu NFC.', 'earned': false, 'progress': 0, 'target': 5, 'hint': 'Pernah absen dengan QR periode ini'},
  ],
};

void main() {
  testWidgets('lencana: ringkasan poin, diraih vs progres, ketuk → syarat & petunjuk', (tester) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(
          body: Padding(
            padding: const EdgeInsets.all(16),
            child: BadgesView(data: Achievements.fromJson(achievementsJson)),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.textContaining('58', findRichText: true), findsOneWidget);
    expect(find.text('2/6 lencana'), findsOneWidget);
    expect(find.text('Diraih'), findsNWidgets(2));
    expect(find.text('3/10'), findsOneWidget);
    expect(find.text('85%/90%'), findsOneWidget);

    await tester.tap(find.text('Tanpa Alpa'));
    await tester.pumpAndSettle();
    expect(find.text('Tidak pernah Alpa.'), findsOneWidget);
    expect(find.text('Tercatat Alpa 1× periode ini'), findsOneWidget);
  });

  testWidgets('Riwayat menampilkan kartu Poin & lencana; server lama tanpa endpoint → disembunyikan', (tester) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);
    Future<void> pump(bool withAchievements) async {
      await tester.pumpWidget(const SizedBox());
      await tester.pumpWidget(
        KartuAnggotaApp(
          home: Scaffold(
            body: MyHistoryTab(
              api: ApiClient(
                baseUrl: 'http://srv',
                client: MockClient((req) async {
                  if (req.url.path == '/api/me/achievements') {
                    return withAchievements ? http.Response(jsonEncode(achievementsJson), 200) : http.Response('{}', 404);
                  }
                  return http.Response(
                    jsonEncode({
                      'by_status': {'hadir': 1},
                      'total': 1,
                      'attendance_rate': 100,
                      'recent': [],
                    }),
                    200,
                  );
                }),
              )..token = 't',
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
    }

    await pump(true);
    expect(find.text('Poin & lencana'), findsOneWidget);
    await pump(false);
    expect(find.text('Poin & lencana'), findsNothing);
    expect(find.text('100%'), findsOneWidget); // riwayat tetap tampil
  });
}
