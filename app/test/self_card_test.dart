import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/nfc/nfc_service.dart';
import 'package:kartuanggota/nfc/ntag.dart';
import 'package:kartuanggota/screens/member/my_card_tab.dart';
import 'package:kartuanggota/screens/member/self_card_screen.dart';
import 'package:material_ui/material_ui.dart';

Map<String, dynamic> card({required bool canWrite, bool canRequest = false, String? pending, bool hasCard = false}) => {
  'state': 'active', 'organization': 'HMPS', 'valid_until': '2027-07-31',
  'member': {'name': 'Budi', 'member_no': 'B01', 'division': 'Humas', 'photo_url': null},
  'card': hasCard ? {'last4': 'ABCD'} : null,
  'self_card': {'can_write': canWrite, 'can_request': canRequest, 'pending_request': pending == null ? null : {'reason': pending}},
};

void main() {
  testWidgets('daftarkan kartu NFC sendiri: tulis lalu aktif; tombol hilang setelah jatah terpakai', (tester) async {
    tester.view.physicalSize = const Size(800, 1800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    var written = false;
    final paths = <String>[];
    final api = ApiClient(
      baseUrl: 'http://srv',
      client: MockClient((req) async {
        paths.add('${req.method} ${req.url.path}');
        switch (req.url.path) {
          case '/api/me/card':
            return http.Response(jsonEncode(card(canWrite: !written, canRequest: written, hasCard: written)), 200);
          case '/api/me/card/init':
            return http.Response(jsonEncode({'id': 9, 'payload': 'card:k1:X:SIG', 'ntag': {'pwd': '01020304', 'pack': '0506'}}), 201);
          case '/api/me/card/9/assign':
            written = true;
            expect((jsonDecode(req.body) as Map)['hw_uid'], '04AABB');
            return http.Response(jsonEncode(card(canWrite: false, canRequest: true, hasCard: true)), 200);
        }
        return http.Response('{}', 404);
      }),
    )..token = 't';
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(
          body: MyCardTab(
            api: api,
            name: 'Budi',
            selfCardScreen: (api) => SelfCardScreen(api: api, writer: (init) async => const WriteResult(model: NtagModel.ntag215, uidHex: '04AABB', protectedNow: true)),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Daftarkan kartu NFC saya'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Mulai daftarkan kartu'));
    await tester.pumpAndSettle();
    expect(find.text('Kartu aktif'), findsOneWidget);
    await tester.tap(find.text('Selesai'));
    await tester.pumpAndSettle();

    expect(paths, containsAllInOrder(['POST /api/me/card/init', 'POST /api/me/card/9/assign']));
    expect(find.text('Daftarkan kartu NFC saya'), findsNothing);
    expect(find.textContaining('Ajukan pengganti'), findsOneWidget);
    expect(find.textContaining('…ABCD'), findsOneWidget);
  });

  testWidgets('ajukan kartu pengganti (hilang) → menunggu persetujuan', (tester) async {
    tester.view.physicalSize = const Size(800, 1800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    String? reason;
    final api = ApiClient(
      baseUrl: 'http://srv',
      client: MockClient((req) async {
        if (req.url.path == '/api/me/card/replacement') {
          reason = (jsonDecode(req.body) as Map)['reason'] as String;
          return http.Response(jsonEncode(card(canWrite: false, pending: 'hilang')), 201);
        }
        return http.Response(jsonEncode(card(canWrite: false, canRequest: reason == null, pending: reason)), 200);
      }),
    )..token = 't';
    await tester.pumpWidget(KartuAnggotaApp(home: Scaffold(body: MyCardTab(api: api, name: 'Budi'))));
    await tester.pumpAndSettle();
    await tester.tap(find.textContaining('Ajukan pengganti'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Ajukan'));
    await tester.pumpAndSettle();
    expect(reason, 'hilang');
    expect(find.text('Permintaan kartu pengganti menunggu persetujuan'), findsOneWidget);
  });
}
