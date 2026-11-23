import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/member/card_pages.dart';
import 'package:kartuanggota/screens/member/my_card_tab.dart';
import 'package:kartuanggota/widgets/member_card.dart';
import 'package:material_ui/material_ui.dart';
import 'package:nfc_manager/nfc_manager.dart' show NfcAvailability;

/// Menu halaman Kartu Saya: balik kartu, detail kartu, riwayat kartu, cek kartu NFC.
final cardJson = {
  'state': 'active',
  'status': 'active',
  'organization': 'HMPS',
  'term': '2026',
  'valid_until': '2027-07-31',
  'member': {'name': 'Budi Santoso', 'member_no': 'B01', 'division': 'Humas', 'photo_url': null},
  'card': {'last4': 'ABCD', 'issued_at': '2026-11-01T03:00:00Z', 'issued_by': 'self'},
  'self_card': {'can_write': false, 'can_request': true, 'writes_left': 0, 'pending_request': null, 'last_decision': null},
};

ApiClient api({List<String>? checked, String result = 'mine'}) => ApiClient(
  baseUrl: 'http://srv',
  client: MockClient((req) async {
    switch (req.url.path) {
      case '/api/me/card/history':
        return http.Response(
          jsonEncode({
            'cards': [
              {'last4': 'ABCD', 'status': 'active', 'issued_at': '2026-11-12T03:00:00Z', 'revoked_at': null, 'issued_by': 'self'},
              {'last4': 'WXYZ', 'status': 'lost', 'issued_at': '2026-09-01T03:00:00Z', 'revoked_at': '2026-11-10T01:00:00Z', 'issued_by': 'admin'},
            ],
            'requests': [
              {'reason': 'hilang', 'status': 'approved', 'note': null, 'review_note': 'Silakan daftar ulang', 'resolution': 'self', 'created_at': '2026-11-10T01:00:00Z'},
            ],
          }),
          200,
        );
      case '/api/me/card/check':
        checked?.add((jsonDecode(req.body) as Map)['payload'] as String);
        return http.Response(jsonEncode({'result': result}), 200);
    }
    return http.Response(jsonEncode(cardJson), 200);
  }),
)..token = 't';

Future<void> pumpCardTab(WidgetTester tester, ApiClient api) async {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 2.5;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    KartuAnggotaApp(
      home: Scaffold(
        body: MyCardTab(api: api, name: 'Budi', nfcAvailability: () async => NfcAvailability.enabled, readCard: () async => 'HMPS:v1:k1:X:Y'),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('kartu aktif bisa dibalik ke sisi belakang dan kembali', (tester) async {
    await pumpCardTab(tester, api());
    expect(find.byType(MemberCardBack), findsNothing);
    await tester.tap(find.byType(FlippableMemberCard));
    await tester.pumpAndSettle();
    expect(find.byType(MemberCardBack), findsOneWidget);
    expect(find.text('Cara memakai kartu'), findsOneWidget);
    expect(find.text('No. kartu …ABCD'), findsOneWidget);
    await tester.tap(find.byType(FlippableMemberCard));
    await tester.pumpAndSettle();
    expect(find.byType(MemberCardBack), findsNothing);
  });

  testWidgets('aksi & menu kartu tampil; Detail kartu menunjukkan cara terbit', (tester) async {
    await pumpCardTab(tester, api());
    for (final t in ['QR Saya', 'Unduh PDF', 'Cek NFC', 'Lapor', 'Detail kartu', 'Riwayat kartu', 'Lapor kartu hilang/rusak']) {
      expect(find.text(t), findsOneWidget, reason: t);
    }
    await tester.ensureVisible(find.text('Detail kartu'));
    await tester.tap(find.text('Detail kartu'));
    await tester.pumpAndSettle();
    expect(find.text('…ABCD'), findsOneWidget);
    expect(find.text('Didaftarkan sendiri'), findsOneWidget);
    expect(find.text('1 Nov 2026'), findsOneWidget);
    expect(find.text('Habis — lewat pengurus'), findsOneWidget);
  });

  testWidgets('Riwayat kartu: kartu lama & laporan beserta keputusan', (tester) async {
    await pumpCardTab(tester, api());
    await tester.ensureVisible(find.text('Riwayat kartu'));
    await tester.tap(find.text('Riwayat kartu'));
    await tester.pumpAndSettle();
    expect(find.text('Kartu …ABCD'), findsOneWidget);
    expect(find.text('Kartu …WXYZ'), findsOneWidget);
    expect(find.text('Hilang'), findsOneWidget);
    expect(find.text('Kartu hilang'), findsOneWidget);
    expect(find.textContaining('Silakan daftar ulang'), findsOneWidget);
  });

  testWidgets('Cek NFC: kartu dibaca lalu diverifikasi server', (tester) async {
    final checked = <String>[];
    await pumpCardTab(tester, api(checked: checked));
    await tester.tap(find.text('Cek NFC'));
    await tester.pumpAndSettle();
    expect(checked, ['HMPS:v1:k1:X:Y']);
    expect(find.text('Kartu Anda aktif'), findsOneWidget);
  });

  for (final (result, title) in [
    ('other', 'Kartu milik anggota lain'),
    ('mine_inactive', 'Kartu Anda sudah nonaktif'),
    ('invalid', 'Bukan kartu anggota yang sah'),
    ('unknown', 'Kartu belum terdaftar'),
  ]) {
    testWidgets('Cek NFC: hasil $result', (tester) async {
      await tester.pumpWidget(
        KartuAnggotaApp(
          home: Scaffold(
            body: CardCheckPage(
              api: api(result: result),
              readCard: () async => 'HMPS:v1:k1:X:Y',
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text(title), findsOneWidget);
      expect(find.text('Cek kartu lagi'), findsOneWidget);
    });
  }
}
