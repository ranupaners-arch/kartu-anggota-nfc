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
import 'package:shared_preferences/shared_preferences.dart';

import 'offline_test.dart' show at, budiCard, rosterJson;

const a = 'http://srv|1', b = 'http://srv|2', lain = 'http://lain|1';
PendingScan scan(String uuid) => PendingScan(clientUuid: uuid, eventId: 7, payload: budiCard, source: 'nfc', scannedAt: at('01:10'));
RejectedScan refused(String uuid) => RejectedScan(clientUuid: uuid, eventId: 7, status: 'blocked', message: 'x', scannedAt: at('01:10'));

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  test('antrean, penolakan, kegiatan & peserta terpisah per akun dan per server', () async {
    final sa = await OfflineStore.open(owner: a);
    await sa.enqueue(scan('u1'));
    await sa.addRejected([refused('r1')]);
    await sa.saveRoster(Roster.fromApi(rosterJson()));
    await sa.saveEvents([const EventSummary(7, 'Rapat')]);
    for (final o in [b, lain]) {
      final s = await OfflineStore.open(owner: o);
      expect((s.pending().length, s.rejected().length, s.loadRoster(7), s.loadEvents(at('01:10')).length), (0, 0, null, 0), reason: o);
      expect(s.othersPending(), 1);
    }
    final again = await OfflineStore.open(owner: a);
    expect((again.pending().length, again.rejected().length, again.loadRoster(7)?.members.length, again.loadEvents(at('01:10')).length, again.othersPending()), (1, 1, 2, 1, 0));
  });

  test('akun lain tidak mengirim scan yang bukan miliknya', () async {
    await (await OfflineStore.open(owner: a)).enqueue(scan('u1'));
    var calls = 0;
    final api = ApiClient(
      baseUrl: 'http://srv',
      client: MockClient((_) async {
        calls++;
        return http.Response('{"results":[]}', 200);
      }),
    );
    await SyncService(api, await OfflineStore.open(owner: b)).syncAll();
    expect(calls, 0);
    expect((await OfflineStore.open(owner: a)).pending(), hasLength(1));
  });

  test('data versi lama pindah ke akun pertama, sekali saja, tanpa ganda', () async {
    final old = await OfflineStore.open();
    await old.enqueue(scan('u1'));
    await old.enqueue(scan('u2'));
    await old.addRejected([refused('r1')]);
    await old.saveRoster(Roster.fromApi(rosterJson()));
    await old.saveEvents([const EventSummary(7, 'Rapat')]);
    // pindah sempat terputus: u1 sudah ada di akun baru
    await OfflineStore(await SharedPreferences.getInstance(), owner: a).enqueue(scan('u1'));

    final sa = await OfflineStore.open(owner: a);
    expect(sa.pending().map((p) => p.clientUuid).toSet(), {'u1', 'u2'});
    expect((sa.pending().length, sa.rejected().length, sa.loadRoster(7)?.members.length, sa.loadEvents(at('01:10')).length), (2, 1, 2, 1));
    expect((old.pending().length, old.rejected().length, old.loadRoster(7)), (0, 0, null));
    final sb = await OfflineStore.open(owner: b);
    expect((sb.pending().length, sb.othersPending()), (0, 2));
  });

  test('scan yang masuk selagi data lama dipindahkan tidak hilang', () async {
    final old = await OfflineStore.open();
    await old.enqueue(scan('u1'));
    final moving = OfflineStore.open(owner: a);
    for (var i = 0; i < 20; i++) {
      await Future<void>.value();
      await old.enqueue(scan('sela$i'));
    }
    await moving;
    final sa = await OfflineStore.open(owner: a);
    expect(sa.pending(), hasLength(21));
    expect(old.pending(), isEmpty);
  });

  test('sesi menyimpan id akun; keluar menghapusnya tetapi antrean tetap', () async {
    await Session(baseUrl: 'http://srv', token: 't', userName: 'Sari', role: 'scanner', userId: 1).save();
    expect((await Session.load())!.owner, a);
    final store = await OfflineStore.open(owner: a);
    await store.enqueue(scan('u1'));
    await store.saveEvents([const EventSummary(7, 'Rapat')]);
    await Session.clear();
    expect(store.pending(), hasLength(1));
    expect(store.loadEvents(at('01:10')), isEmpty);
    await Session(baseUrl: 'http://srv', token: 't', userName: 'Lama', role: 'scanner').save();
    expect((await Session.load())!.owner, isNull);
  });

  testWidgets('sesi versi lama: id akun diambil dari server, antrean lama terkirim', (tester) async {
    await (await OfflineStore.open(owner: 'http://srv|9')).enqueue(scan('asing'));
    await (await OfflineStore.open()).enqueue(scan('u1'));
    final sent = <String>[];
    final session = Session(baseUrl: 'http://srv', token: 't', userName: 'Sari', role: 'scanner');
    await session.save();
    final api = ApiClient(
      baseUrl: 'http://srv',
      client: MockClient((r) async {
        Object body = {'data': <Object>[]};
        if (r.url.path == '/api/me') body = {'id': 1, 'name': 'Sari', 'role': 'scanner', 'member_id': null};
        if (r.url.path.endsWith('/scan/batch')) {
          final scans = ((jsonDecode(r.body) as Map<String, dynamic>)['scans'] as List).cast<Map<String, dynamic>>();
          sent.addAll([for (final s in scans) s['client_uuid'] as String]);
          body = {
            'results': [
              for (final s in scans) {'client_uuid': s['client_uuid'], 'status': 'ok'},
            ],
          };
        }
        return http.Response(jsonEncode(body), 200);
      }),
    )..token = 't';
    await tester.pumpWidget(KartuAnggotaApp(home: EventsScreen(session: session, api: api)));
    await tester.pumpAndSettle();
    await tester.pump(const Duration(seconds: 31));
    await tester.pumpAndSettle();
    expect(session.owner, a);
    expect((await Session.load())!.owner, a);
    expect(sent.toSet(), {'u1'});
    expect((await OfflineStore.open(owner: a)).pending(), isEmpty);
  });

  testWidgets('beranda memberi tahu ada scan milik akun lain', (tester) async {
    await (await OfflineStore.open(owner: a)).enqueue(scan('u1'));
    final session = Session(baseUrl: 'http://srv', token: 't', userName: 'Budi', role: 'scanner', userId: 2);
    final api = ApiClient(baseUrl: 'http://srv', client: MockClient((_) async => http.Response('{"data":[]}', 200)))..token = 't';
    await tester.pumpWidget(KartuAnggotaApp(home: EventsScreen(session: session, api: api)));
    await tester.pumpAndSettle();
    expect(find.text('1 scan milik akun lain'), findsOneWidget);
  });
}
