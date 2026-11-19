import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/app/home.dart';
import 'package:kartuanggota/app/session.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/events_screen.dart';
import 'package:kartuanggota/screens/member/excuse_screen.dart';
import 'package:kartuanggota/screens/member/member_home_screen.dart';
import 'package:kartuanggota/screens/member/my_events_tab.dart';
import 'package:kartuanggota/screens/member/my_qr_tab.dart';
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

MockClient server({List<http.Request>? seen, int qrCalls = 0, void Function()? onQr}) => MockClient((req) async {
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
    case '/api/excuses':
      return http.Response(jsonEncode({'id': 1}), 201);
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

  testWidgets('layar anggota: tiga tab, riwayat tampil', (tester) async {
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: MemberHomeScreen(session: session('member'), api: api(server())),
      ),
    );
    await tester.pump();
    await tester.tap(find.text('Riwayat').last);
    await tester.pumpAndSettle();
    expect(find.text('100%'), findsOneWidget);
    expect(find.text('Hadir 3'), findsOneWidget);
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
}
