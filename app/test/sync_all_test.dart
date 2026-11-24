import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/app/session.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/offline/offline_store.dart';
import 'package:kartuanggota/offline/sync_service.dart';
import 'package:kartuanggota/screens/events_screen.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';

ApiClient apiOf(Future<http.Response> Function(http.Request) f) => ApiClient(baseUrl: 'http://srv', client: MockClient(f))..token = 't';

/// Server: semua scan diterima, tidak ada kegiatan berlangsung.
http.Response accept(http.Request r) => r.url.path.endsWith('/scan/batch')
    ? http.Response(
        jsonEncode({
          'results': [
            for (final s in (jsonDecode(r.body) as Map<String, dynamic>)['scans'] as List) {'client_uuid': (s as Map<String, dynamic>)['client_uuid'], 'status': 'ok'},
          ],
        }),
        200,
      )
    : http.Response(jsonEncode({'data': <Object>[]}), 200);

Future<OfflineStore> storeWith(List<int> eventIds) async {
  SharedPreferences.setMockInitialValues({});
  final store = await OfflineStore.open();
  for (final id in eventIds) {
    await store.enqueue(PendingScan(clientUuid: 'u$id', eventId: id, payload: 'p', source: 'nfc', scannedAt: DateTime(2026, 11, 24)));
  }
  return store;
}

Future<void> pumpHome(WidgetTester tester, OfflineStore store, ApiClient api, {bool embedded = false}) async {
  await tester.pumpWidget(
    KartuAnggotaApp(
      home: EventsScreen(
        session: Session(baseUrl: 'http://srv', token: 't', userName: 'Sari', role: 'scanner', hasMember: embedded),
        api: api,
        offlineStore: () async => store,
        embedded: embedded,
      ),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  test('semua kegiatan dikirim; satu kegiatan ditolak server tidak menahan yang lain', () async {
    final store = await storeWith([7, 8, 9]);
    final r = await SyncService(apiOf((q) async => q.url.path.contains('/8/') ? http.Response('{}', 404) : accept(q)), store).syncAll();
    expect((r.sent, r.accepted, r.remaining), (2, 2, 1));
    expect(r.error, isNotNull);
    expect(store.pending().single.eventId, 8);
  });

  for (final code in [401, 429, 503]) {
    test('HTTP $code: berhenti, antrean utuh', () async {
      final store = await storeWith([7, 8]);
      var calls = 0;
      await SyncService(
        apiOf((_) async {
          calls++;
          return http.Response('{}', code);
        }),
        store,
      ).syncAll();
      expect((calls, store.pending().length), (1, 2));
    });
  }

  test('jaringan putus: cukup satu percobaan', () async {
    final store = await storeWith([7, 8]);
    var calls = 0;
    await SyncService(
      apiOf((_) async {
        calls++;
        throw http.ClientException('putus');
      }),
      store,
    ).syncAll();
    expect((calls, store.pending().length), (1, 2));
  });

  for (final embedded in [false, true]) {
    testWidgets('beranda mengirim antrean kegiatan yang sudah tutup (tab Tugas: $embedded)', (tester) async {
      final store = await storeWith([7]);
      await pumpHome(tester, store, apiOf((r) async => accept(r)), embedded: embedded);
      expect(store.pending(), isEmpty);
      expect(find.textContaining('1 scan tersinkron'), findsOneWidget);
      expect(find.textContaining('belum tersinkron'), findsNothing);
    });
  }

  testWidgets('sinyal kembali: terkirim otomatis tiap 30 detik dan saat app aktif lagi', (tester) async {
    for (final resume in [false, true]) {
      var online = false;
      final store = await storeWith([7]);
      await pumpHome(tester, store, apiOf((r) async => online ? accept(r) : throw http.ClientException('putus')));
      expect(find.text('1 scan offline belum tersinkron'), findsOneWidget);
      online = true;
      if (resume) {
        for (final s in [AppLifecycleState.inactive, AppLifecycleState.hidden, AppLifecycleState.paused, AppLifecycleState.hidden, AppLifecycleState.inactive, AppLifecycleState.resumed]) {
          tester.binding.handleAppLifecycleStateChanged(s);
        }
      } else {
        await tester.pump(const Duration(seconds: 30));
      }
      await tester.pumpAndSettle();
      expect(store.pending(), isEmpty, reason: 'resume: $resume');
      await tester.pumpWidget(const SizedBox());
    }
  });
}
