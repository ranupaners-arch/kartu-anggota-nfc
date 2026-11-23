import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/app/session.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/offline/offline_store.dart';
import 'package:kartuanggota/screens/events_screen.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';

ApiClient api(int events) => ApiClient(
  baseUrl: 'http://srv',
  client: MockClient(
    (req) async => http.Response(
      jsonEncode({
        'data': [
          for (var i = 1; i <= events; i++) {'id': i, 'title': 'Rapat $i', 'location': 'Aula', 'checkin_closes_at': '2099-01-01T03:00:00Z'},
        ],
      }),
      200,
    ),
  ),
)..token = 't';

Future<void> pump(WidgetTester tester, {required String role, int events = 2, int pending = 0}) async {
  SharedPreferences.setMockInitialValues({});
  final store = await OfflineStore.open();
  for (var i = 0; i < pending; i++) {
    await store.enqueue(PendingScan(clientUuid: 'u$i', eventId: 1, payload: 'p', source: 'nfc', scannedAt: DateTime(2026, 11, 23)));
  }
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 2.5;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    KartuAnggotaApp(
      home: EventsScreen(
        session: Session(baseUrl: 'http://srv', token: 't', userName: 'Sari Dewi', role: role),
        api: api(events),
        offlineStore: () async => store,
      ),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('beranda petugas: sapaan, peran, menu cepat, kegiatan dengan tombol Mulai scan', (tester) async {
    await pump(tester, role: 'scanner');
    expect(find.text('Halo, Sari 👋'), findsOneWidget);
    expect(find.text('Masuk sebagai Petugas Scan'), findsOneWidget);
    for (final m in ['Pindai', 'Alat NFC', 'Panduan']) {
      expect(find.text(m), findsOneWidget, reason: m);
    }
    expect(find.text('Terbitkan Kartu'), findsNothing); // khusus Super Admin
    expect(find.text('Rapat 1'), findsOneWidget);
    expect(find.text('Mulai scan'), findsNWidgets(2));
    expect(find.textContaining('scan offline'), findsNothing);
  });

  testWidgets('Super Admin melihat Terbitkan Kartu; scan offline tertunda ditampilkan', (tester) async {
    await pump(tester, role: 'super_admin', pending: 3);
    expect(find.text('Terbitkan Kartu'), findsOneWidget);
    expect(find.text('3 scan offline belum tersinkron'), findsOneWidget);
  });

  testWidgets('Pindai dengan beberapa kegiatan → pilih kegiatan dulu; tanpa kegiatan → pesan', (tester) async {
    await pump(tester, role: 'scanner');
    await tester.tap(find.text('Pindai'));
    await tester.pumpAndSettle();
    expect(find.text('Pilih kegiatan'), findsOneWidget);
    await tester.tapAt(const Offset(5, 5)); // tutup lembar
    await tester.pumpAndSettle();

    await tester.pumpWidget(const SizedBox()); // layar baru (state lama tidak dipakai ulang)
    await pump(tester, role: 'scanner', events: 0);
    expect(find.text('Tidak ada kegiatan berlangsung'), findsOneWidget);
    await tester.tap(find.text('Pindai'));
    await tester.pump();
    expect(find.text('Belum ada kegiatan yang jendela check-in-nya dibuka.'), findsOneWidget);
  });

  testWidgets('Panduan petugas terbuka di halaman sendiri', (tester) async {
    await pump(tester, role: 'scanner');
    await tester.tap(find.text('Panduan'));
    await tester.pumpAndSettle();
    expect(find.text('Panduan petugas'), findsOneWidget);
    expect(find.text('Tidak ada sinyal'), findsOneWidget);
  });
}
