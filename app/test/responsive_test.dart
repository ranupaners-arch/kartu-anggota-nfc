import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/app/server_config.dart';
import 'package:kartuanggota/app/session.dart';
import 'package:kartuanggota/feedback/scan_feedback.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/nfc/nfc_service.dart';
import 'package:kartuanggota/offline/offline_store.dart';
import 'package:kartuanggota/screens/connect_screen.dart';
import 'package:kartuanggota/screens/events_screen.dart';
import 'package:kartuanggota/screens/issue_card_screen.dart';
import 'package:kartuanggota/screens/login_screen.dart';
import 'package:kartuanggota/screens/member/excuse_screen.dart';
import 'package:kartuanggota/screens/member/member_home_screen.dart';
import 'package:kartuanggota/screens/scan_screen.dart';
import 'package:kartuanggota/screens/welcome_screen.dart';
import 'package:kartuanggota/widgets/scan_result_card.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'nfc_service_test.dart' show FakeNfc;

/// Ukuran layar (dp) yang diuji: HP kecil, HP, HP landscape, tablet potret & lanskap.
const sizes = {'hp-kecil': Size(320, 568), 'hp': Size(390, 844), 'landscape': Size(844, 390), 'tablet': Size(800, 1280), 'tablet-lanskap': Size(1280, 800)};

/// Skala huruf Android (pengaturan Ukuran font): normal, besar, terbesar.
const scales = [1.0, 1.3, 2.0];

const longName = 'Muhammad Rizky Pratama Wijayakusuma Nugroho';

final session = Session(baseUrl: 'http://srv', token: 't', userName: 'Petugas Scan Lapangan', role: 'scanner');
final memberSession = Session(baseUrl: 'http://srv', token: 't', userName: longName, role: 'member');

ApiClient api() => ApiClient(baseUrl: 'http://srv', client: MockClient((req) async {
      final body = switch (req.url.path) {
        '/api/events' => {'data': [for (var i = 0; i < 3; i++) {'id': i + 1, 'title': 'Rapat Kerja Nasional Himpunan Mahasiswa ${i + 1}', 'location': 'Aula Gedung Serbaguna Lantai 3', 'checkin_closes_at': '2099-01-01T03:00:00Z'}]},
        '/api/me/qr' => {'payload': 'card:qr:TOKEN', 'expires_in': 90},
        '/api/me/card' => {'state': 'active', 'status': 'active', 'organization': 'Himpunan Mahasiswa Program Studi Teknik Informatika', 'term': '2026/2027', 'valid_until': '2027-07-31', 'member': {'name': longName, 'member_no': '240100123456', 'division': 'Penelitian dan Pengembangan Keilmuan', 'cohort': '2024', 'photo_url': null}, 'card': {'last4': 'ABCD'}},
        '/api/me/events' => {'data': [{'id': 7, 'title': 'Rapat Kerja Nasional Himpunan Mahasiswa', 'location': 'Aula Gedung Serbaguna', 'starts_at': '2099-01-01T02:00:00Z', 'checkin_closes_at': '2099-01-01T03:00:00Z', 'closed_at': null, 'is_mandatory': true, 'my_status': null, 'excuse': {'type': 'sakit', 'status': 'pending', 'review_note': 'Mohon lampirkan surat dokter'}, 'can_request_excuse': true, 'sessions': 3, 'present_sessions': 1}]},
        '/api/me/history' => {'term': '2026/2027', 'by_status': {'hadir': 12, 'telat': 3, 'izin': 1, 'sakit': 2, 'alpa': 1}, 'total': 19, 'attendance_rate': 78.9, 'recent': [{'event': 'Rapat Kerja Nasional Himpunan Mahasiswa', 'date': '2026-11-01T02:00:00Z', 'status': 'telat'}]},
        '/api/events/1/roster' => {'event': {'id': 1, 'title': 'Rapat', 'checkin_opens_at': '2026-01-01T00:00:00Z', 'late_after': '2026-01-01T00:00:00Z', 'checkin_closes_at': '2099-01-01T00:00:00Z', 'closed_at': null}, 'members': [], 'generated_at': '2026-01-01T00:00:00Z'},
        _ => <String, dynamic>{'data': []},
      };
      return http.Response(jsonEncode(body), 200);
    }))..token = 't';

final sessionsJson = [
  {'id': 11, 'title': 'Hari Pertama Pembukaan', 'checkin_opens_at': '2026-01-01T00:00:00Z', 'late_after': '2026-01-01T00:00:00Z', 'checkin_closes_at': '2099-01-01T00:00:00Z'},
  {'id': 12, 'title': 'Hari Kedua Pelatihan', 'checkin_opens_at': '2099-01-02T00:00:00Z', 'late_after': '2099-01-02T00:00:00Z', 'checkin_closes_at': '2099-01-02T03:00:00Z'},
];

/// Layar yang diuji. Dibuat ulang tiap ukuran (state baru).
Map<String, Future<Widget> Function()> screens() => {
      'Sambutan': () async => const WelcomeScreen(server: ServerConfig(url: 'https://absen.himpunan-mahasiswa.ac.id', name: 'Himpunan Mahasiswa Program Studi Teknik Informatika')),
      'Hubungkan': () async => const ConnectScreen(),
      'Masuk': () async => const LoginScreen(server: ServerConfig(url: 'https://absen.himpunan-mahasiswa.ac.id', name: 'Himpunan Mahasiswa Program Studi')),
      'Kegiatan': () async => EventsScreen(session: session, api: api()),
      'Scan': () async => ScanScreen(
            session: session, api: api(), nfc: NfcService(FakeNfc()),
            store: OfflineStore(await SharedPreferences.getInstance()),
            feedback: ScanFeedback(player: (_) async {}, haptic: (_) async {}),
            event: EventSummary.fromJson({'id': 1, 'title': 'Rapat Kerja Nasional Himpunan Mahasiswa', 'sessions': sessionsJson, 'current_session_id': 11}),
          ),
      'Hasil scan': () async => Scaffold(body: ListView(padding: const EdgeInsets.all(16), children: [
            ScanResultCard(result: const ScanResult(status: 'ok', attendanceStatus: 'telat', message: 'Tercatat, perlu ditinjau admin.', memberName: longName, memberNo: '2401234567890', division: 'Pengembangan Sumber Daya Mahasiswa', offline: true)),
          ])),
      'Terbitkan kartu': () async => IssueCardScreen(session: session, api: api()),
      'Anggota': () async => MemberHomeScreen(session: memberSession, api: api()),
      'Ajukan izin': () async => ExcuseScreen(api: api(), event: MemberEvent.fromJson({'id': 7, 'title': 'Rapat Kerja Nasional Himpunan Mahasiswa', 'starts_at': '2099-01-01T02:00:00Z', 'checkin_closes_at': '2099-01-01T03:00:00Z'}), picker: () async => null),
    };

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  testWidgets('semua layar utama tanpa luapan di 5 ukuran × 3 skala huruf', (tester) async {
    final failures = <String>[];
    addTearDown(tester.view.reset);
    addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);

    for (final MapEntry(key: name, value: build) in screens().entries) {
      for (final MapEntry(key: sizeName, value: size) in sizes.entries) {
        for (final scale in scales) {
          tester.view.physicalSize = size;
          tester.view.devicePixelRatio = 1;
          tester.platformDispatcher.textScaleFactorTestValue = scale;
          await tester.pumpWidget(KartuAnggotaApp(home: await build()));
          await tester.pump();
          await tester.pump(const Duration(milliseconds: 100));
          final errors = <Object>[];
          for (Object? e = tester.takeException(); e != null; e = tester.takeException()) {
            errors.add(e);
          }
          if (errors.isNotEmpty) {
            failures.add('$name @ $sizeName ×$scale: ${errors.first.toString().split('\n').first}');
          }
          await tester.pumpWidget(const SizedBox()); // hentikan timer layar sebelumnya
        }
      }
    }
    expect(failures, isEmpty, reason: failures.join('\n'));
  });

  testWidgets('landscape: layar Scan dua panel; tablet: konten tidak membentang', (tester) async {
    addTearDown(tester.view.reset);
    tester.view.devicePixelRatio = 1;

    tester.view.physicalSize = sizes['landscape']!;
    await tester.pumpWidget(KartuAnggotaApp(home: await screens()['Scan']!()));
    await tester.pump();
    final mode = tester.getTopLeft(find.text('Kartu NFC'));
    final count = tester.getTopLeft(find.textContaining('berhasil tercatat'));
    expect(count.dx, greaterThan(mode.dx + 300), reason: 'hasil di panel kanan');
    expect(count.dy, lessThan(400), reason: 'tanpa menggulir');
    await tester.pumpWidget(const SizedBox());

    tester.view.physicalSize = sizes['tablet']!;
    await tester.pumpWidget(KartuAnggotaApp(home: EventsScreen(session: session, api: api())));
    await tester.pumpAndSettle();
    final card = tester.getRect(find.ancestor(of: find.text('Rapat Kerja Nasional Himpunan Mahasiswa 1'), matching: find.byType(ShadCard)).first);
    expect(card.width, lessThanOrEqualTo(720));
    expect(card.center.dx, closeTo(400, 1), reason: 'rata tengah');
  });
}
