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
import 'package:nfc_manager/nfc_manager.dart' show NfcAvailability;

Map<String, dynamic> card({required bool canWrite, bool canRequest = false, String? pending, bool hasCard = false, Map<String, dynamic>? decision}) => {
  'state': 'active',
  'organization': 'HMPS',
  'valid_until': '2027-07-31',
  'member': {'name': 'Budi', 'member_no': 'B01', 'division': 'Humas', 'photo_url': null},
  'card': hasCard ? {'last4': 'ABCD'} : null,
  'self_card': {
    'can_write': canWrite,
    'can_request': canRequest,
    'pending_request': pending == null ? null : {'reason': pending},
    'last_decision': decision,
  },
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
            return http.Response(
              jsonEncode({
                'id': 9,
                'payload': 'card:k1:X:SIG',
                'ntag': {'pwd': '01020304', 'pack': '0506'},
              }),
              201,
            );
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
            nfcAvailability: () async => NfcAvailability.enabled,
            selfCardScreen: (api) => SelfCardScreen(
              api: api,
              writer: (init) async => const WriteResult(model: NtagModel.ntag215, uidHex: '04AABB', protectedNow: true),
            ),
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
    expect(find.text('Lapor kartu hilang/rusak'), findsOneWidget);
    expect(find.textContaining('…ABCD'), findsOneWidget);
  });

  testWidgets('lapor kartu hilang → menunggu tindak lanjut pengurus', (tester) async {
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
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(
          body: MyCardTab(api: api, name: 'Budi', nfcAvailability: () async => NfcAvailability.enabled),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Lapor kartu hilang/rusak'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Kirim laporan'));
    await tester.pumpAndSettle();
    expect(reason, 'hilang');
    expect(find.text('Laporan kartu menunggu tindak lanjut pengurus'), findsOneWidget);
  });

  Future<void> pumpTab(WidgetTester tester, NfcAvailability nfc, Map<String, dynamic> json, {List<String>? reasons}) async {
    tester.view.physicalSize = const Size(800, 1800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final api = ApiClient(
      baseUrl: 'http://srv',
      client: MockClient((req) async {
        if (req.url.path == '/api/me/card/replacement') {
          reasons?.add((jsonDecode(req.body) as Map)['reason'] as String);
          return http.Response(jsonEncode(card(canWrite: true, pending: 'tanpa_nfc')), 201);
        }
        return http.Response(jsonEncode(json), 200);
      }),
    )..token = 't';
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(
          body: MyCardTab(api: api, name: 'Budi', nfcAvailability: () async => nfc),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('plugin NFC melempar error langsung (perangkat tak didukung): halaman tetap tampil', (tester) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);
    final api = ApiClient(baseUrl: 'http://srv', client: MockClient((req) async => http.Response(jsonEncode(card(canWrite: true, canRequest: true)), 200)))..token = 't';
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(
          body: MyCardTab(api: api, name: 'Budi', nfcAvailability: () => throw UnsupportedError('linux is not supported')),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    expect(find.text('HP ini tidak mendukung NFC'), findsOneWidget);
    expect(find.text('Tampilkan QR (kartu tertinggal)'), findsOneWidget);
  });

  testWidgets('HP tanpa NFC: arahkan ke minta pengurus terbitkan kartu', (tester) async {
    final reasons = <String>[];
    await pumpTab(tester, NfcAvailability.unsupported, card(canWrite: true, canRequest: true), reasons: reasons);
    expect(find.text('HP ini tidak mendukung NFC'), findsOneWidget);
    expect(find.text('Daftarkan kartu NFC saya'), findsNothing);
    await tester.tap(find.text('Minta pengurus terbitkan kartu'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Kirim laporan'));
    await tester.pumpAndSettle();
    expect(reasons, ['tanpa_nfc']);
  });

  testWidgets('NFC mati: ajakan menyalakan NFC', (tester) async {
    await pumpTab(tester, NfcAvailability.disabled, card(canWrite: true, canRequest: true));
    expect(find.text('NFC di HP ini sedang mati'), findsOneWidget);
    expect(find.text('Buka pengaturan NFC'), findsOneWidget);
  });

  testWidgets('keputusan pengurus tampil: pengurus menerbitkan + catatan', (tester) async {
    await pumpTab(
      tester,
      NfcAvailability.unsupported,
      card(canWrite: false, canRequest: true, decision: {'reason': 'tanpa_nfc', 'status': 'approved', 'resolution': 'admin', 'review_note': 'Ambil di sekretariat Senin'}),
    );
    expect(find.text('Pengurus akan menerbitkan kartu Anda'), findsOneWidget);
    expect(find.textContaining('Ambil di sekretariat Senin'), findsOneWidget);
  });

  testWidgets('unduh kartu PDF untuk dicetak', (tester) async {
    tester.view.physicalSize = const Size(800, 1800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    List<int>? opened;
    final api = ApiClient(
      baseUrl: 'http://srv',
      client: MockClient(
        (req) async => req.url.path == '/api/me/card.pdf'
            ? http.Response.bytes([37, 80, 68, 70], 200, headers: {'content-type': 'application/pdf'})
            : http.Response(jsonEncode(card(canWrite: false, canRequest: true, hasCard: true)), 200),
      ),
    )..token = 't';
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(
          body: MyCardTab(
            api: api,
            name: 'Budi',
            nfcAvailability: () async => NfcAvailability.enabled,
            openPdf: (b) async {
              opened = b;
              return null;
            },
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Unduh kartu (PDF) untuk dicetak'));
    await tester.pumpAndSettle();
    expect(opened, [37, 80, 68, 70]);
  });
}
