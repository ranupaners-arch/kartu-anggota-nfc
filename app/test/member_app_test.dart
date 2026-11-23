import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/app/home.dart';
import 'package:kartuanggota/app/session.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/events_screen.dart';
import 'package:kartuanggota/screens/member/account_tab.dart';
import 'package:kartuanggota/screens/member/excuse_screen.dart';
import 'package:kartuanggota/screens/member/member_home_screen.dart';
import 'package:kartuanggota/screens/member/my_events_tab.dart';
import 'package:kartuanggota/screens/member/my_history_tab.dart';
import 'package:kartuanggota/screens/member/my_qr_tab.dart';
import 'package:flutter/services.dart';
import 'package:material_ui/material_ui.dart';
import 'package:qr_flutter/qr_flutter.dart';

Session session(String role) => Session(baseUrl: 'http://srv', token: 't', userName: 'Budi', role: role);

final eventJson = {
  'id': 7,
  'title': 'Rapat Pleno',
  'location': 'Aula',
  'starts_at': '2099-01-01T02:00:00Z',
  'checkin_closes_at': '2099-01-01T03:00:00Z',
  'closed_at': null,
  'is_mandatory': true,
  'my_status': null,
  'excuse': null,
  'can_request_excuse': true,
};

MockClient server({List<http.Request>? seen, int qrCalls = 0, void Function()? onQr, bool announcements = true}) => MockClient((req) async {
  seen?.add(req);
  switch (req.url.path) {
    case '/api/me/qr':
      onQr?.call();
      return http.Response(jsonEncode({'payload': 'card:qr:TOKEN${DateTime.now().microsecond}', 'expires_in': 90}), 200);
    case '/api/me/events':
      return http.Response(
        jsonEncode({
          'data': [eventJson],
        }),
        200,
      );
    case '/api/me/history':
      return http.Response(
        jsonEncode({
          'term': '2026',
          'by_status': {'hadir': 3, 'telat': 1, 'izin': 0, 'sakit': 0, 'alpa': 0},
          'total': 4,
          'attendance_rate': 100,
          'recent': [
            {'event': 'Rapat Pleno', 'date': '2026-11-01T02:00:00Z', 'status': 'hadir', 'source': 'nfc'},
          ],
        }),
        200,
      );
    case '/api/me/announcements':
      if (!announcements) return http.Response(jsonEncode({'data': []}), 200);
      return http.Response(
        jsonEncode({
          'data': [
            {'id': 1, 'title': 'Sekre tutup Jumat', 'body': 'Libur nasional.', 'pinned': true, 'created_at': '2026-11-01T02:00:00Z', 'division': null},
          ],
        }),
        200,
      );
    case '/api/me/card':
      return http.Response(
        jsonEncode({
          'state': 'active',
          'status': 'active',
          'organization': 'HMPS',
          'term': '2026',
          'valid_until': '2027-07-31',
          'member': {'name': 'Budi Santoso', 'member_no': '2401005', 'division': 'Inti', 'cohort': '2024', 'photo_url': null},
          'card': {'last4': 'ABCD'},
          'self_card': {'can_write': false, 'can_request': true, 'pending_request': null},
        }),
        200,
      );
    case '/api/excuses':
      return http.Response(jsonEncode({'id': 1}), 201);
    case '/api/me/calendar':
      return http.Response(jsonEncode({'url': 'http://srv/jadwal/${'a' * 48}.ics'}), 200);
    case '/api/me/calendar/reset':
      return http.Response(jsonEncode({'url': 'http://srv/jadwal/${'b' * 48}.ics'}), 200);
  }
  return http.Response('{}', 404);
});

ApiClient api(MockClient c) => ApiClient(baseUrl: 'http://srv', client: c)..token = 't';

void main() {
  test('layar utama sesuai peran', () {
    expect(homeFor(session('member')), isA<MemberHomeScreen>());
    for (final role in ['scanner', 'division_admin', 'super_admin']) {
      expect(homeFor(session(role)), isA<EventsScreen>(), reason: role);
    }
  });

  testWidgets('QR Saya tampil dan diperbarui otomatis', (tester) async {
    var calls = 0;
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(
          body: MyQrTab(
            api: api(server(onQr: () => calls++)),
            name: 'Budi',
            refreshEvery: const Duration(seconds: 5),
          ),
        ),
      ),
    );
    await tester.pump();
    await tester.pump();
    expect(find.byType(QrImageView), findsOneWidget);
    expect(calls, 1);
    await tester.pump(const Duration(seconds: 6));
    expect(calls, 2); // dimuat ulang sebelum kedaluwarsa
    await tester.pumpWidget(const SizedBox()); // hentikan timer
  });

  test('label status kegiatan untuk anggota', () {
    MemberEvent e(Map<String, dynamic> over) => MemberEvent.fromJson({...eventJson, ...over});
    expect(MyEventsTabStatus.of(e({'my_status': 'telat'})), 'Telat');
    expect(
      MyEventsTabStatus.of(
        e({
          'excuse': {'type': 'sakit', 'status': 'pending'},
        }),
      ),
      'Sakit menunggu',
    );
    expect(
      MyEventsTabStatus.of(
        e({
          'excuse': {'type': 'izin', 'status': 'rejected'},
        }),
      ),
      'Izin ditolak',
    );
    expect(MyEventsTabStatus.of(e({})), 'Akan datang');
    expect(MyEventsTabStatus.of(e({'my_status': 'alpa', 'sessions': 2, 'present_sessions': 1})), 'Hadir 1/2 sesi');
  });

  testWidgets('daftar kegiatan menampilkan tombol izin bila boleh', (tester) async {
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(body: MyEventsTab(api: api(server()))),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Rapat Pleno'), findsOneWidget);
    expect(find.text('Ajukan izin'), findsOneWidget);
  });

  group('formulir izin', () {
    final event = MemberEvent.fromJson(eventJson);

    testWidgets('alasan wajib; lampiran salah jenis & terlalu besar ditolak', (tester) async {
      var next = (name: 'virus.exe', bytes: [1, 2, 3]);
      await tester.pumpWidget(
        KartuAnggotaApp(
          home: ExcuseScreen(api: api(server()), event: event, picker: () async => next),
        ),
      );
      await tester.tap(find.text('Kirim pengajuan'));
      await tester.pumpAndSettle();
      expect(find.text('Alasan wajib diisi.'), findsOneWidget);

      await tester.tap(find.textContaining('Pilih gambar'));
      await tester.pumpAndSettle();
      expect(find.text('Lampiran harus gambar (JPG/PNG) atau PDF.'), findsOneWidget);

      next = (name: 'besar.pdf', bytes: List.filled(maxAttachmentBytes + 1, 0));
      await tester.tap(find.textContaining('Pilih gambar'));
      await tester.pumpAndSettle();
      expect(find.text('Lampiran maksimal 5 MB.'), findsOneWidget);
    });

    testWidgets('kirim izin sakit + lampiran ke server', (tester) async {
      final seen = <http.Request>[];
      await tester.pumpWidget(
        KartuAnggotaApp(
          home: ExcuseScreen(
            api: api(server(seen: seen)),
            event: event,
            picker: () async => (name: 'surat.jpg', bytes: [0xFF, 0xD8, 0xFF]),
          ),
        ),
      );
      await tester.tap(find.text('Sakit'));
      await tester.enterText(find.byType(EditableText), 'Demam tinggi');
      await tester.tap(find.textContaining('Pilih gambar'));
      await tester.pumpAndSettle();
      expect(find.text('surat.jpg'), findsOneWidget);
      await tester.tap(find.text('Kirim pengajuan'));
      await tester.pumpAndSettle();

      final req = seen.single;
      expect((req.method, req.url.path, req.headers['Authorization']), ('POST', '/api/excuses', 'Bearer t'));
      final body = latin1.decode(req.bodyBytes);
      for (final part in ['name="event_id"\r\n\r\n7', 'name="type"\r\n\r\nsakit', 'name="reason"\r\n\r\nDemam tinggi', 'filename="surat.jpg"']) {
        expect(body, contains(part));
      }
      expect(find.byType(ExcuseScreen), findsNothing); // kembali setelah berhasil
    });
  });

  testWidgets('layar anggota: empat tab, riwayat tampil', (tester) async {
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: MemberHomeScreen(session: session('member'), api: api(server())),
      ),
    );
    await tester.pump();
    await tester.tap(find.text('Riwayat').last);
    await tester.pumpAndSettle();
    expect(find.text('100%'), findsOneWidget);
    // Kotak status ala portal: label "Hadir" + angka 3.
    expect(find.descendant(of: find.byType(MyHistoryTab), matching: find.text('3')), findsOneWidget);
    expect(find.text('Rapat Pleno'), findsWidgets); // riwayat terbaru
    expect(find.text('Kehadiran Anda perlu perhatian'), findsNothing); // tanpa peringatan dari server
    await tester.pumpWidget(const SizedBox());
  });

  test('peringatan kehadiran dari server dibaca', () {
    final h = MemberHistory.fromJson({
      'by_status': {'alpa': 3},
      'total': 3,
      'attendance_rate': 0,
      'recent': [],
      'warning': {'at_risk': true, 'alpa_streak': 3, 'rate': 0, 'total': 3},
    });
    expect((h.atRisk, h.alpaStreak), (true, 3));
    expect(MemberHistory.fromJson({'by_status': <String, dynamic>{}, 'total': 0, 'recent': []}).atRisk, isFalse); // server lama
  });

  test('pengingat: kegiatan 24 jam ke depan tanpa status/izin', () {
    final now = DateTime(2026, 11, 20, 8);
    MemberEvent ev(Duration from, [Map<String, dynamic> over = const {}]) => MemberEvent.fromJson({...eventJson, 'starts_at': now.add(from).toUtc().toIso8601String(), ...over});
    final list = [
      ev(const Duration(hours: 3)),
      ev(const Duration(hours: 30)),
      ev(const Duration(hours: -1)),
      ev(const Duration(hours: 5), {'my_status': 'hadir'}),
      ev(const Duration(hours: 6), {
        'excuse': {'type': 'izin', 'status': 'pending'},
      }),
    ];
    expect(eventReminders(list, now), hasLength(1));
  });

  testWidgets('tab Kegiatan menampilkan pengumuman', (tester) async {
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(body: MyEventsTab(api: api(server()))),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Sekre tutup Jumat'), findsOneWidget);
    expect(find.text('Rapat Pleno'), findsOneWidget); // kegiatan tetap tampil
  });

  testWidgets('Kegiatan: langganan jadwal — tampilkan, salin, buat tautan baru', (tester) async {
    final seen = <http.Request>[];
    String? copied;
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
      if (call.method == 'Clipboard.setData') copied = (call.arguments as Map)['text'] as String;
      return null;
    });
    addTearDown(() => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, null));
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(
          body: MyEventsTab(api: api(server(seen: seen))),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('Langganan jadwal ke kalender'));
    await tester.tap(find.text('Langganan jadwal ke kalender'));
    await tester.pumpAndSettle();
    expect(find.text('http://srv/jadwal/${'a' * 48}.ics'), findsOneWidget);

    await tester.tap(find.text('Salin tautan'));
    await tester.pumpAndSettle();
    expect(copied, 'http://srv/jadwal/${'a' * 48}.ics');
    expect(find.text('Tersalin'), findsOneWidget);

    await tester.tap(find.text('Buat tautan baru'));
    await tester.pumpAndSettle();
    expect(find.text('http://srv/jadwal/${'b' * 48}.ics'), findsOneWidget);
    expect(seen.where((r) => r.url.path == '/api/me/calendar/reset' && r.method == 'POST'), hasLength(1));
  });

  testWidgets('Riwayat: unduh rapor PDF', (tester) async {
    List<int>? opened;
    final seen = <http.Request>[];
    final c = MockClient((req) async {
      seen.add(req);
      if (req.url.path == '/api/me/report-card') return http.Response.bytes([0x25, 0x50, 0x44, 0x46], 200, headers: {'content-type': 'application/pdf'});
      // Jawab langsung (request yang sudah difinalisasi tidak bisa diteruskan ke mock lain).
      return http.Response(
        jsonEncode({
          'term': '2026',
          'by_status': {'hadir': 1},
          'total': 1,
          'attendance_rate': 100,
          'recent': [],
        }),
        200,
      );
    });
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(
          body: MyHistoryTab(
            api: api(c),
            openPdf: (b) async {
              opened = b;
              return null;
            },
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Unduh rapor PDF'));
    await tester.pumpAndSettle();
    expect(opened, [0x25, 0x50, 0x44, 0x46]); // %PDF
    expect(seen.last.headers['Authorization'], 'Bearer t');
  });

  test('rupiah: pemisah ribuan titik', () {
    expect(rupiah(5000), 'Rp5.000');
    expect(rupiah(1250000), 'Rp1.250.000');
    expect(rupiah(0), 'Rp0');
  });

  testWidgets('Riwayat menampilkan denda QR', (tester) async {
    final c = MockClient(
      (req) async => http.Response(
        jsonEncode({
          'term': '2026',
          'by_status': {'hadir': 1},
          'total': 1,
          'attendance_rate': 100,
          'recent': [],
          'fines': {
            'unpaid_total': 7500,
            'items': [
              {'id': 1, 'amount': 7500, 'status': 'unpaid', 'event': 'Rapat Pleno', 'date': '2026-11-01T02:00:00Z'},
            ],
          },
        }),
        200,
      ),
    );
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(body: MyHistoryTab(api: api(c))),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Denda absen pakai QR'), findsOneWidget);
    expect(find.textContaining('Rp7.500'), findsWidgets);
    expect(find.text('Belum lunas'), findsOneWidget);
  });

  testWidgets('Beranda: kartu, menu cepat membuka halaman sendiri (bukan pindah tab)', (tester) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: MemberHomeScreen(session: session('member'), api: api(server())),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Halo, Budi 👋'), findsOneWidget);
    expect(find.text('HMPS'), findsWidgets); // subjudul bilah atas
    for (final m in ['QR Saya', 'Izin', 'Rapor', 'Kartu', 'Denda', 'Jadwal', 'Data Diri', 'Panduan']) {
      expect(find.text(m), findsOneWidget, reason: m);
    }
    await tester.scrollUntilVisible(find.text('Sekre tutup Jumat'), 200, scrollable: find.byType(Scrollable).first);
    expect(find.text('Kegiatan terdekat'), findsOneWidget);
    await tester.drag(find.byType(Scrollable).first, const Offset(0, 2000));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Denda'));
    await tester.pumpAndSettle();
    expect(find.text('Tidak ada denda'), findsOneWidget);
    expect(find.byType(BackButton), findsOneWidget); // halaman baru, bisa kembali
    await tester.tap(find.byType(BackButton));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Data Diri'));
    await tester.pumpAndSettle();
    expect(find.text('2401005'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('Akun: ganti password & langganan jadwal seperti portal, menu membuka halaman', (tester) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: MemberHomeScreen(session: session('member'), api: api(server())),
      ),
    );
    await tester.pump();
    await tester.tap(find.text('Akun').last);
    await tester.pumpAndSettle();
    expect(find.text('Ganti password'), findsOneWidget);
    expect(find.text('Buat tautan langganan'), findsOneWidget);
    await tester.scrollUntilVisible(
      find.text('Rapor kehadiran'),
      200,
      scrollable: find.descendant(of: find.byType(AccountTab), matching: find.byType(Scrollable)).first,
    );
    await tester.ensureVisible(find.text('Rapor kehadiran'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Rapor kehadiran'));
    await tester.pumpAndSettle();
    expect(find.text('tingkat kehadiran dari 4 kegiatan'), findsOneWidget);
    expect(find.byType(BackButton), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('Kegiatan tanpa pengumuman: kegiatan tampil, tanpa tulisan "Belum ada kegiatan"', (tester) async {
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(body: MyEventsTab(api: api(server(announcements: false)))),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Rapat Pleno'), findsOneWidget);
    expect(find.text('Belum ada kegiatan'), findsNothing);
  });
}
