import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/app/session.dart';
import 'package:kartuanggota/feedback/scan_feedback.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/nfc/card_payload.dart';
import 'package:kartuanggota/nfc/nfc_service.dart';
import 'package:kartuanggota/offline/offline_store.dart';
import 'package:kartuanggota/offline/roster.dart';
import 'package:kartuanggota/screens/events_screen.dart';
import 'package:kartuanggota/screens/scan_screen.dart';
import 'package:material_ui/material_ui.dart';
import 'package:nfc_manager/nfc_manager.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'nfc_service_test.dart' show FakeNfc;
import 'offline_test.dart' show at, budiCard, rosterJson;

/// NFC palsu: satu kartu ditempel saat [tap] dipanggil.
class OneTapNfc extends NfcService {
  OneTapNfc() : super(FakeNfc());
  var _next = Completer<ReadResult>();
  void tap() {
    final c = _next;
    _next = Completer<ReadResult>();
    c.complete(
      ReadResult(
        info: const TagInfo(uidMasked: '04', techList: []),
        text: budiCard,
        payload: CardPayload.parse(budiCard),
      ),
    );
  }

  @override
  Future<NfcAvailability> availability() async => NfcAvailability.enabled;
  @override
  Future<ReadResult> read({String prefix = CardPayload.defaultPrefix}) => _next.future;
  @override
  Future<void> stop() async {}
}

final session = Session(baseUrl: 'http://srv', token: 't', userName: 'Sari', role: 'scanner');
ApiClient apiOf(Future<http.Response> Function(http.Request) f) => ApiClient(baseUrl: 'http://srv', client: MockClient(f))..token = 't';
final eventsBody = jsonEncode({
  'data': [
    {'id': 7, 'title': 'Rapat Pleno', 'checkin_closes_at': '2099-01-01T03:00:00Z'},
  ],
});

Future<OfflineStore> freshStore() async {
  SharedPreferences.setMockInitialValues({});
  return OfflineStore.open();
}

Future<OneTapNfc> pumpScan(WidgetTester tester, OfflineStore store, ApiClient api) async {
  final nfc = OneTapNfc();
  await tester.pumpWidget(
    KartuAnggotaApp(
      home: ScanScreen(
        session: session,
        event: const EventSummary(7, 'Rapat Pleno'),
        api: api,
        nfc: nfc,
        store: store,
        feedback: ScanFeedback(player: (_) async {}, haptic: (_) async {}),
        clock: () => at('01:10'),
      ),
    ),
  );
  await tester.pump();
  return nfc;
}

Future<void> pumpHome(WidgetTester tester, OfflineStore store, ApiClient api) async {
  await tester.pumpWidget(
    KartuAnggotaApp(home: EventsScreen(key: UniqueKey(), session: session, api: api, offlineStore: () async => store)),
  );
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('beranda dibuka tanpa internet: kegiatan terakhir tetap bisa dipindai', (tester) async {
    final store = await freshStore();
    await pumpHome(tester, store, apiOf((r) async => http.Response(r.url.path == '/api/events' ? eventsBody : '{}', 200)));
    expect(find.text('Mulai scan'), findsOneWidget);
    await pumpHome(tester, store, apiOf((_) async => throw http.ClientException('putus')));
    expect(find.text('Rapat Pleno'), findsOneWidget);
    expect(find.text('Mulai scan'), findsOneWidget);
    expect(find.textContaining('Mode offline'), findsOneWidget);
  });

  testWidgets('beranda online mengunduh data peserta tanpa membuka layar Scan', (tester) async {
    final store = await freshStore();
    await pumpHome(
      tester,
      store,
      apiOf(
        (r) async => http.Response(
          r.url.path.endsWith('/roster')
              ? jsonEncode(rosterJson())
              : r.url.path == '/api/events'
              ? eventsBody
              : '{}',
          200,
        ),
      ),
    );
    expect(store.loadRoster(7)?.members, hasLength(2));
  });

  testWidgets('keluar akun menghapus daftar kegiatan tersimpan', (tester) async {
    final store = await freshStore();
    await store.saveEvents([const EventSummary(7, 'Rapat Pleno')]);
    await Session.clear();
    expect(store.loadEvents(at('01:10')), isEmpty);
  });

  testWidgets('kartu ditempel saat permintaan peserta masih menggantung: pakai data tersimpan', (tester) async {
    final store = await freshStore();
    await store.saveRoster(Roster.fromApi(rosterJson()));
    final nfc = await pumpScan(tester, store, apiOf((r) => r.url.path.endsWith('/roster') ? Completer<http.Response>().future : throw http.ClientException('putus')));
    nfc.tap();
    await tester.pump();
    await tester.pump();
    expect(store.pending(), hasLength(1));
    await tester.pump(const Duration(seconds: 11)); // habiskan timer batas waktu
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('scan tak dijawab: tersimpan offline dalam 4 detik', (tester) async {
    final store = await freshStore();
    await store.saveRoster(Roster.fromApi(rosterJson()));
    final nfc = await pumpScan(tester, store, apiOf((r) => Completer<http.Response>().future));
    await tester.pump();
    nfc.tap();
    await tester.pump();
    await tester.pump(const Duration(seconds: 4));
    await tester.pump();
    final queued = store.pending().length;
    await tester.pump(const Duration(seconds: 10));
    await tester.pumpWidget(const SizedBox());
    expect(queued, 1);
  });

  for (final code in [502, 503, 504]) {
    testWidgets('gateway $code (server mati di belakang proxy): scan diantre', (tester) async {
      final store = await freshStore();
      await store.saveRoster(Roster.fromApi(rosterJson()));
      final nfc = await pumpScan(tester, store, apiOf((_) async => http.Response('<html>Bad gateway</html>', code)));
      await tester.pump();
      nfc.tap();
      await tester.pump();
      await tester.pump();
      expect(store.pending(), hasLength(1));
      await tester.pumpWidget(const SizedBox());
    });
  }
}
