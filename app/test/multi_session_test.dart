import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/app/session.dart';
import 'package:kartuanggota/feedback/scan_feedback.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/nfc/nfc_service.dart';
import 'package:kartuanggota/offline/offline_store.dart';
import 'package:kartuanggota/offline/offline_verifier.dart';
import 'package:kartuanggota/offline/roster.dart';
import 'package:kartuanggota/screens/scan_screen.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'nfc_service_test.dart' show FakeNfc;
import 'offline_test.dart' show at, budiCard, rosterJson;

/// Hari 1 (sesi utama) 01.00–03.00 UTC, telat 01.30; Hari 2 keesokan hari 05.30–07.00 UTC, telat 06.10.
final sessionsJson = [
  {'id': 11, 'title': 'Hari 1', 'checkin_opens_at': '2026-11-17T01:00:00.000000Z', 'late_after': '2026-11-17T01:30:00.000000Z', 'checkin_closes_at': '2026-11-17T03:00:00.000000Z'},
  {'id': 12, 'title': 'Hari 2', 'checkin_opens_at': '2026-11-18T05:30:00.000000Z', 'late_after': '2026-11-18T06:10:00.000000Z', 'checkin_closes_at': '2026-11-18T07:00:00.000000Z'},
];

Roster multiRoster() {
  final j = rosterJson();
  (j['event'] as Map<String, dynamic>)['sessions'] = sessionsJson;
  return Roster.fromApi(j);
}

void main() {
  test('daftar kegiatan: sesi & sesi berjalan dibaca; server lama tanpa sesi tetap jalan', () {
    final e = EventSummary.fromJson({'id': 7, 'title': 'Pelatihan', 'sessions': sessionsJson, 'current_session_id': 12});
    expect(e.sessions.map((s) => s.title).toList(), ['Hari 1', 'Hari 2']);
    expect(e.currentSessionId, 12);
    expect(EventSummary.fromJson({'id': 7, 'title': 'Lama'}).sessions, isEmpty);
  });

  test('roster menyimpan sesi (bolak-balik penyimpanan lokal)', () {
    final again = Roster.fromApi(jsonDecode(jsonEncode(multiRoster().toApiJson())) as Map<String, dynamic>);
    expect(again.sessions.map((s) => '${s.id}@${s.lateAfter.toIso8601String()}').toList(), [
      '11@2026-11-17T01:30:00.000Z', '12@2026-11-18T06:10:00.000Z',
    ]);
  });

  test('offline memakai jendela & batas telat sesi yang dipilih', () {
    final r = multiRoster();
    final day2 = r.sessions[1];
    final twoDays = DateTime.parse('2026-11-18T06:00:00Z');
    expect(OfflineVerifier.check(r, budiCard, twoDays, session: day2).attendanceStatus, 'hadir');
    expect(OfflineVerifier.check(r, budiCard, DateTime.parse('2026-11-18T06:20:00Z'), session: day2).attendanceStatus, 'telat');
    expect(OfflineVerifier.check(r, budiCard, at('01:10'), session: day2).message, 'Di luar jendela check-in sesi Hari 2.');
    expect(OfflineVerifier.check(r, budiCard, at('01:10')).ok, isTrue); // tanpa sesi: jendela kegiatan
  });

  test('session_id ikut terkirim: scan online & antrean offline', () async {
    final bodies = <Map<String, dynamic>>[];
    final api = ApiClient(baseUrl: 'http://srv', client: MockClient((req) async {
      bodies.add(jsonDecode(req.body) as Map<String, dynamic>);
      return http.Response(jsonEncode({'status': 'ok', 'message': 'Berhasil.', 'results': []}), 200);
    }))..token = 't';
    await api.scan(7, budiCard, sessionId: 12);
    await api.scan(7, budiCard);
    expect((bodies[0]['session_id'], bodies[1].containsKey('session_id')), (12, false));

    final p = PendingScan(clientUuid: 'u', eventId: 7, payload: budiCard, source: 'nfc', scannedAt: at('01:10'), sessionId: 12);
    expect(p.toBatchItem()['session_id'], 12);
    expect(PendingScan.fromJson(jsonDecode(jsonEncode(p.toJson())) as Map<String, dynamic>).sessionId, 12);
    final old = PendingScan.fromJson({'client_uuid': 'u', 'event_id': 7, 'payload': budiCard, 'source': 'nfc', 'scanned_at': '2026-11-17T01:10:00Z'});
    expect((old.sessionId, old.toBatchItem().containsKey('session_id')), (null, false)); // antrean dari versi lama
  });

  testWidgets('layar Scan: sesi berjalan terpilih, petugas bisa memilih sesi lain', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();
    final event = EventSummary.fromJson({'id': 7, 'title': 'Pelatihan', 'sessions': sessionsJson, 'current_session_id': 12});
    final api = ApiClient(baseUrl: 'http://srv', client: MockClient((_) async => http.Response('{}', 500)))..token = 't';
    await tester.pumpWidget(KartuAnggotaApp(home: ScanScreen(
      session: Session(baseUrl: 'http://srv', token: 't', userName: 'Petugas', role: 'scanner'),
      event: event, api: api, nfc: NfcService(FakeNfc()), store: OfflineStore(prefs),
      feedback: ScanFeedback(player: (_) async {}, haptic: (_) async {}),
      clock: () => DateTime.parse('2026-11-18T06:00:00Z'),
    )));
    await tester.pump();

    ShadButton button(String label) => tester.widget<ShadButton>(find.ancestor(of: find.text(label), matching: find.byType(ShadButton)));
    expect(button('Hari 2').variant, ShadButtonVariant.primary);
    expect(button('Hari 1').variant, ShadButtonVariant.outline);

    await tester.tap(find.text('Hari 1'));
    await tester.pump();
    expect(button('Hari 1').variant, ShadButtonVariant.primary);
    expect(find.textContaining('Check-in sesi ini sudah ditutup'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });
}
