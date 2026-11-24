import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/member/event_detail_page.dart';
import 'package:material_ui/material_ui.dart';

Map<String, dynamic> event(Map<String, dynamic>? rsvp) => {
  'id': 7,
  'title': 'Makrab',
  'starts_at': '2099-01-01T01:00:00Z',
  'checkin_closes_at': '2099-01-01T03:00:00Z',
  'rsvp': rsvp,
};

Map<String, dynamic> rsvp({String? status, bool open = true, int? quota = 2, int going = 0, int? position}) => {
  'status': status,
  'open': open,
  'deadline': '2099-01-01T01:00:00Z',
  'quota': quota,
  'going': going,
  'position': position,
};

void main() {
  Future<List<bool>> pump(WidgetTester tester, Map<String, dynamic>? before, {Map<String, dynamic>? after, int code = 200, double scale = 1}) async {
    tester.view.physicalSize = scale == 1 ? const Size(432, 960) : const Size(320, 568);
    tester.view.devicePixelRatio = 1;
    tester.platformDispatcher.textScaleFactorTestValue = scale;
    addTearDown(tester.view.reset);
    addTearDown(tester.platformDispatcher.clearAllTestValues);
    final sent = <bool>[];
    final api = ApiClient(
      baseUrl: 'http://srv',
      client: MockClient((r) async {
        if (r.url.path == '/api/me/events/7/rsvp') {
          sent.add((jsonDecode(r.body) as Map<String, dynamic>)['going'] as bool);
          return http.Response(jsonEncode(code == 200 ? after : {'message': 'RSVP sudah ditutup.'}), code);
        }
        return http.Response(
          jsonEncode({
            'data': [event(after ?? before)],
          }),
          200,
        );
      }),
    );
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(
          body: EventDetailPage(api: api, event: MemberEvent.fromJson(event(before))),
        ),
      ),
    );
    await tester.pumpAndSettle();
    return sent;
  }

  testWidgets('ikut → status & jumlah diperbarui, bisa dibatalkan', (tester) async {
    final sent = await pump(tester, rsvp(), after: rsvp(status: 'going', going: 1));
    expect(find.text('Apakah Anda ikut?'), findsOneWidget);
    await tester.tap(find.text('Ikut'));
    await tester.pumpAndSettle();
    expect(find.text('Anda ikut'), findsOneWidget);
    expect(find.textContaining('1 dari 2 tempat terisi'), findsOneWidget);
    await tester.tap(find.text('Batal ikut'));
    await tester.pumpAndSettle();
    expect(sent, [true, false]);
  });

  testWidgets('kuota penuh: tombol daftar tunggu & nomor antrean', (tester) async {
    await pump(tester, rsvp(going: 2), after: rsvp(status: 'waitlist', going: 2, position: 3));
    await tester.tap(find.text('Masuk daftar tunggu'));
    await tester.pumpAndSettle();
    expect(find.text('Anda di daftar tunggu nomor 3'), findsOneWidget);
  });

  testWidgets('ditolak server: pesan tampil', (tester) async {
    await pump(tester, rsvp(), code: 422);
    await tester.tap(find.text('Tidak ikut'));
    await tester.pumpAndSettle();
    expect(find.text('RSVP sudah ditutup.'), findsOneWidget);
  });

  testWidgets('RSVP ditutup: tanpa tombol; kegiatan tanpa RSVP: tanpa kartu', (tester) async {
    await pump(tester, rsvp(open: false, status: 'declined'));
    expect(find.text('Anda tidak ikut'), findsOneWidget);
    expect(find.text('Ikut'), findsNothing);
    await tester.pumpWidget(const SizedBox());
    await pump(tester, null);
    expect(find.text('RSVP'), findsNothing);
  });

  testWidgets('HP kecil dengan huruf besar tidak meluber', (tester) async {
    await pump(tester, rsvp(status: 'waitlist', going: 2, position: 12), scale: 2);
    expect(tester.takeException(), isNull);
  });
}
