import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/member/member_home_screen.dart';
import 'package:kartuanggota/screens/member/my_history_tab.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'member_app_test.dart' show api, server, session;

void main() {
  testWidgets('tagihan iuran di lonceng → ketuk membuka Riwayat', (tester) async {
    SharedPreferences.setMockInitialValues({});
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);
    final base = server();
    final client = MockClient((r) async {
      if (r.url.path != '/api/me/notifications') return http.Response.fromStream(await base.send(http.Request(r.method, r.url)..body = r.body));
      return http.Response(
        jsonEncode({
          'data': [
            {'id': 'due:1', 'type': 'due', 'title': 'Tagihan Iuran November: Rp20.000', 'body': 'Bayar ke bendahara', 'at': '2026-11-24T01:00:00Z'},
          ],
        }),
        200,
      );
    });
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: MemberHomeScreen(session: session('member'), api: api(client)),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byIcon(LucideIcons.bell).first);
    await tester.pumpAndSettle();
    expect(find.byIcon(LucideIcons.banknote), findsOneWidget);
    await tester.tap(find.text('Tagihan Iuran November: Rp20.000'));
    await tester.pumpAndSettle();
    expect(find.byType(MyHistoryTab), findsOneWidget);
    expect(find.byType(BackButton), findsWidgets);
    await tester.pumpWidget(const SizedBox());
  });
}
