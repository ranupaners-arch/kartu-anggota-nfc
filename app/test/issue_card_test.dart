import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/app/session.dart';
import 'package:kartuanggota/cards/card_issuer.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/nfc/ntag.dart';
import 'package:kartuanggota/nfc/nfc_service.dart';
import 'package:kartuanggota/screens/issue_card_screen.dart';
import 'package:material_ui/material_ui.dart';

const budi = MemberHit(id: 5, memberNo: '2401005', name: 'Budi');
const written = WriteResult(model: NtagModel.ntag215, uidHex: '04a1b2c3d4e5f6', protectedNow: true);

/// Server palsu: mencatat urutan panggilan.
MockClient fakeServer(List<String> calls, {bool failAssign = false}) => MockClient((req) => fakeHandler(calls, req, failAssign: failAssign));

Future<http.Response> fakeHandler(List<String> calls, http.Request req, {bool failAssign = false}) async {
  calls.add('${req.method} ${req.url.path}');
  switch (req.url.path) {
    case '/api/cards/init':
      return http.Response(
        jsonEncode({
          'id': 77,
          'public_id': '01X',
          'payload': 'card:v1:k1:01X:aa',
          'ntag': {'pwd': 'a1b2c3d4', 'pack': 'e5f6'},
        }),
        201,
      );
    case '/api/cards/77/assign':
      if (failAssign) throw http.ClientException('offline');
      expect(jsonDecode(req.body), {'member_id': 5, 'hw_uid': '04a1b2c3d4e5f6'});
      return http.Response(jsonEncode({'id': 77, 'status': 'active'}), 200);
    case '/api/members':
      return http.Response(
        jsonEncode({
          'data': [
            {
              'id': 5,
              'member_no': '2401005',
              'name': 'Budi',
              'memberships': [
                {
                  'division': {'name': 'Humas'},
                },
              ],
              'active_card': {'id': 1},
            },
          ],
        }),
        200,
      );
  }
  return http.Response('{}', 404);
}

void main() {
  test('urutan: init → tulis (payload & password dari server) → tautkan dengan UID', () async {
    final calls = <String>[];
    CardInit? usedInit;
    final steps = <IssueStep>[];
    final issuer = CardIssuer(ApiClient(baseUrl: 'http://srv', client: fakeServer(calls)), (init) async {
      usedInit = init;
      calls.add('WRITE');
      return written;
    });

    await issuer.issue(budi, steps.add);
    expect(calls, ['POST /api/cards/init', 'WRITE', 'POST /api/cards/77/assign']);
    expect((usedInit!.payload, usedInit!.pwd, usedInit!.pack), ('card:v1:k1:01X:aa', 'a1b2c3d4', 'e5f6'));
    expect(steps, [IssueStep.preparing, IssueStep.waitingCard, IssueStep.linking, IssueStep.done]);
    expect(issuer.needsRelink, isFalse);
  });

  test('penulisan gagal → kartu TIDAK ditautkan (tidak tercatat aktif)', () async {
    final calls = <String>[];
    final issuer = CardIssuer(ApiClient(baseUrl: 'http://srv', client: fakeServer(calls)), (_) async => throw NfcException('Kartu dilepas.'));

    await expectLater(issuer.issue(budi, (_) {}), throwsA(isA<NfcException>()));
    expect(calls, ['POST /api/cards/init']);
    expect(issuer.needsRelink, isFalse);
  });

  test('tautkan gagal setelah kartu tertulis → tautkan ulang kartu & UID yang sama tanpa menulis ulang', () async {
    final calls = <String>[];
    var offline = true;
    var writes = 0;
    final server = MockClient((req) async {
      if (req.url.path == '/api/cards/77/assign' && offline) throw http.ClientException('offline');
      return fakeHandler(calls, req);
    });
    final issuer = CardIssuer(ApiClient(baseUrl: 'http://srv', client: server), (_) async {
      writes++;
      return written;
    });

    await expectLater(issuer.issue(budi, (_) {}), throwsA(isA<http.ClientException>()));
    expect(issuer.needsRelink, isTrue);

    offline = false; // jaringan pulih
    final steps = <IssueStep>[];
    await issuer.relink(budi, steps.add);
    expect(steps, [IssueStep.linking, IssueStep.done]);
    expect(writes, 1); // kartu tidak ditulis ulang
    expect(calls.where((c) => c == 'POST /api/cards/77/assign'), hasLength(1)); // assign dengan id kartu yang sama
    expect(issuer.needsRelink, isFalse);
  });

  testWidgets('layar: cari → pilih (peringatan kartu lama) → terbitkan → kartu aktif', (tester) async {
    final calls = <String>[];
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: IssueCardScreen(
          session: Session(baseUrl: 'http://srv', token: 't', userName: 'Admin', role: 'super_admin'),
          api: ApiClient(baseUrl: 'http://srv', client: fakeServer(calls))..token = 't',
          writer: (_) async => written,
        ),
      ),
    );
    await tester.enterText(find.byType(EditableText), 'bud');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    expect(find.text('Budi'), findsOneWidget);
    expect(find.text('Punya kartu'), findsOneWidget);

    await tester.tap(find.text('Budi'));
    await tester.pumpAndSettle();
    expect(find.textContaining('Kartu lama anggota ini akan diblokir'), findsOneWidget);

    await tester.tap(find.text('Terbitkan & tulis kartu'));
    await tester.pumpAndSettle();
    expect(find.text('Kartu aktif'), findsOneWidget);
    expect(calls, contains('POST /api/cards/77/assign'));
  });
}
