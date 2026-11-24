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
import 'package:kartuanggota/screens/login_screen.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'offline_test.dart' show at, budiCard;

const owner = 'http://srv|1';
ApiClient apiOf(Future<http.Response> Function(http.Request) f) => ApiClient(baseUrl: 'http://srv', client: MockClient(f))..token = 't';
List<Map<String, dynamic>> scansOf(http.Request r) => ((jsonDecode(r.body) as Map<String, dynamic>)['scans'] as List).cast();

Future<OfflineStore> storeWith(int n) async {
  SharedPreferences.setMockInitialValues({});
  final store = await OfflineStore.open(owner: owner);
  for (var i = 0; i < n; i++) {
    await store.enqueue(PendingScan(clientUuid: 'u$i', eventId: 7 + i % 2, payload: budiCard, source: 'nfc', scannedAt: at('01:10')));
  }
  return store;
}

void main() {
  test('antrean semua kegiatan dititipkan bertahap; yang tidak sah masuk catatan ditolak', () async {
    final store = await storeWith(201);
    final sizes = <int>[];
    final r = await SyncService(
      apiOf((q) async {
        expect(q.url.path, '/api/devices/held-scans');
        expect(scansOf(q).first.containsKey('event_id'), isTrue);
        sizes.add(scansOf(q).length);
        return http.Response(
          jsonEncode({
            'results': [
              for (final s in scansOf(q)) {'client_uuid': s['client_uuid'], 'status': s['client_uuid'] == 'u0' ? 'invalid' : 'held', 'message': 'x'},
            ],
          }),
          200,
        );
      }),
      store,
    ).uploadHeld();
    expect((r.held, r.retry, store.pending().length), (200, false, 0));
    expect(sizes, [200, 1]);
    expect(store.rejected().single.clientUuid, 'u0');
  });

  for (final (code, retry) in [(403, false), (404, false), (422, false), (429, true), (503, true)]) {
    test('HTTP $code: antrean utuh, coba lagi: $retry', () async {
      final store = await storeWith(2);
      final r = await SyncService(apiOf((_) async => http.Response('{}', code)), store).uploadHeld();
      expect((r.held, r.retry, store.pending().length), (0, retry, 2));
    });
  }

  Future<void> pumpHome(WidgetTester tester, Future<http.Response> Function(http.Request) held) async {
    final session = Session(baseUrl: 'http://srv', token: 't', userName: 'Sari', role: 'scanner', userId: 1);
    await session.save();
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: EventsScreen(
          session: session,
          api: apiOf((r) async => r.url.path == '/api/devices/held-scans' ? await held(r) : http.Response('{}', 401)),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('perangkat dicabut: scan dititipkan lalu ke layar Masuk dengan penjelasan', (tester) async {
    final store = await storeWith(1);
    await pumpHome(
      tester,
      (r) async => http.Response(
        jsonEncode({
          'results': [
            for (final s in scansOf(r)) {'client_uuid': s['client_uuid'], 'status': 'held', 'message': ''},
          ],
        }),
        200,
      ),
    );
    expect(store.pending(), isEmpty);
    expect(find.byType(LoginScreen), findsOneWidget);
    expect(find.textContaining('scan offline dikirim untuk ditinjau'), findsOneWidget);
    expect(await Session.load(), isNull);
  });

  testWidgets('koneksi putus saat menitipkan: token tidak dibuang', (tester) async {
    final store = await storeWith(1);
    await pumpHome(tester, (_) async => throw http.ClientException('putus'));
    expect(store.pending(), hasLength(1));
    expect(find.byType(LoginScreen), findsNothing);
    expect(await Session.load(), isNotNull);
  });

  testWidgets('sesi berakhir biasa (bukan dicabut): tetap keluar, antrean utuh', (tester) async {
    final store = await storeWith(1);
    await pumpHome(tester, (_) async => http.Response('{}', 403));
    expect(find.byType(LoginScreen), findsOneWidget);
    expect(store.pending(), hasLength(1));
  });
}
