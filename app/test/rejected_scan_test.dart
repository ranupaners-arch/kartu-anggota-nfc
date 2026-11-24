import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/app/session.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/offline/offline_store.dart';
import 'package:kartuanggota/offline/roster.dart';
import 'package:kartuanggota/offline/sync_service.dart';
import 'package:kartuanggota/screens/events_screen.dart';
import 'package:kartuanggota/screens/rejected_scans_screen.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'offline_test.dart' show at, budiCard, rosterJson;

ApiClient apiWith(List<Map<String, dynamic>> results) => ApiClient(
  baseUrl: 'http://srv',
  client: MockClient((r) async => http.Response(jsonEncode(r.url.path.endsWith('/scan/batch') ? {'results': results} : {'data': <Object>[]}), 200)),
)..token = 't';

PendingScan scan(String uuid) => PendingScan(clientUuid: uuid, eventId: 7, payload: budiCard, source: 'nfc', scannedAt: at('01:10'));

Future<OfflineStore> freshStore() async {
  SharedPreferences.setMockInitialValues({});
  return OfflineStore.open();
}

const blocked = {
  'client_uuid': 'a',
  'status': 'blocked',
  'message': 'Kartu diblokir.',
  'member': {'id': 1, 'member_no': '001', 'name': 'Budi'},
};

void main() {
  test('yang ditolak disimpan beserta alasan; ok & duplikat tidak', () async {
    final store = await freshStore();
    await store.saveRoster(Roster.fromApi(rosterJson()));
    for (final u in ['a', 'b', 'c']) {
      await store.enqueue(scan(u));
    }
    final r = await SyncService(
      apiWith([
        blocked,
        {'client_uuid': 'b', 'status': 'ok'},
        {'client_uuid': 'c', 'status': 'duplicate'},
      ]),
      store,
    ).sync(7);
    expect((r.sent, r.rejected, store.pending().length), (3, 1, 0));
    final x = store.rejected().single;
    expect((x.clientUuid, x.memberName, x.memberNo, x.eventTitle, x.status, x.message), ('a', 'Budi', '001', 'Rapat Pleno', 'blocked', 'Kartu diblokir.'));
    expect(x.scannedAt, at('01:10'));
    final raw = (await SharedPreferences.getInstance()).getString('offline_rejected')!;
    expect(raw, isNot(contains(budiCard))); // isi kartu tidak ikut disimpan
  });

  test('server tidak menyebut anggota: nama diambil dari data peserta', () async {
    final store = await freshStore();
    await store.saveRoster(Roster.fromApi(rosterJson()));
    await store.enqueue(scan('a'));
    await SyncService(
      apiWith([
        {'client_uuid': 'a', 'status': 'invalid', 'message': 'Kartu tidak dikenali.', 'member': null},
      ]),
      store,
    ).sync(7);
    expect(store.rejected().single.memberName, 'Budi');
  });

  test('uuid sama tidak dicatat dua kali; data rusak dianggap kosong', () async {
    final store = await freshStore();
    for (var i = 0; i < 2; i++) {
      await store.enqueue(scan('a'));
      await SyncService(apiWith([blocked]), store).sync(7);
    }
    expect(store.rejected(), hasLength(1));
    await (await SharedPreferences.getInstance()).setString('offline_rejected', 'rusak');
    expect(store.rejected(), isEmpty);
  });

  testWidgets('beranda → rincian → sudah ditangani', (tester) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);
    final store = await freshStore();
    await store.enqueue(scan('a'));
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: EventsScreen(
          session: Session(baseUrl: 'http://srv', token: 't', userName: 'Sari', role: 'scanner'),
          api: apiWith([blocked]),
          offlineStore: () async => store,
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('1 scan ditolak server'), findsOneWidget);
    await tester.tap(find.text('Lihat rincian'));
    await tester.pumpAndSettle();
    expect(find.text('Budi'), findsOneWidget);
    expect(find.text('Kartu diblokir: Kartu diblokir.'), findsOneWidget);

    await tester.tap(find.text('Sudah ditangani'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Batal'));
    await tester.pumpAndSettle();
    expect(store.rejected(), hasLength(1));
    await tester.tap(find.text('Sudah ditangani'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Hapus catatan'));
    await tester.pumpAndSettle();
    expect(store.rejected(), isEmpty);
    expect(find.text('Tidak ada scan yang ditolak.'), findsOneWidget);
    await tester.tap(find.byType(BackButton));
    await tester.pumpAndSettle();
    expect(find.textContaining('ditolak server'), findsNothing);
  });

  testWidgets('layar rincian tidak meluber di HP kecil dengan huruf besar', (tester) async {
    tester.view.physicalSize = const Size(320, 568);
    tester.view.devicePixelRatio = 1;
    tester.platformDispatcher.textScaleFactorTestValue = 2;
    addTearDown(tester.view.reset);
    addTearDown(tester.platformDispatcher.clearAllTestValues);
    final store = await freshStore();
    await store.addRejected([
      RejectedScan(
        clientUuid: 'a',
        eventId: 7,
        eventTitle: 'Rapat Pleno Tahunan Pengurus Besar',
        memberName: 'Nama Anggota Yang Sangat Panjang Sekali',
        status: 'outside_window',
        message: 'Di luar jendela check-in.',
        scannedAt: at('01:10'),
      ),
    ]);
    await tester.pumpWidget(KartuAnggotaApp(home: RejectedScansScreen(store: store)));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
  });
}
