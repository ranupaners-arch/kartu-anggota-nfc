import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/app/home.dart';
import 'package:kartuanggota/app/session.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/nfc/card_payload.dart';
import 'package:kartuanggota/nfc/nfc_service.dart';
import 'package:kartuanggota/offline/offline_store.dart';
import 'package:kartuanggota/offline/roster.dart';
import 'package:kartuanggota/screens/events_screen.dart';
import 'package:kartuanggota/screens/member/member_home_screen.dart';
import 'package:kartuanggota/screens/scan_screen.dart';
import 'package:kartuanggota/feedback/scan_feedback.dart';
import 'package:material_ui/material_ui.dart';
import 'package:nfc_manager/nfc_manager.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'member_app_test.dart' as member show api, server;
import 'nfc_service_test.dart' show FakeNfc;
import 'offline_test.dart' show at, budiCard, rosterJson;

const owner = 'http://srv|1';
ApiClient apiOf(Future<http.Response> Function(http.Request) f) => ApiClient(baseUrl: 'http://srv', client: MockClient(f))..token = 't';
Future<http.Response> down(http.Request _) async => throw http.ClientException('putus');
Future<http.Response> hang(http.Request _) => Completer<http.Response>().future;
Session officer({bool hasMember = false, String role = 'scanner'}) => Session(baseUrl: 'http://srv', token: 't', userName: 'Sari', role: role, hasMember: hasMember, userId: 1);

Map<String, dynamic> ev(int id, String title, Duration opens, Duration closes) => {
  'id': id,
  'title': title,
  'checkin_opens_at': DateTime.now().toUtc().add(opens).toIso8601String(),
  'checkin_closes_at': DateTime.now().toUtc().add(closes).toIso8601String(),
};

/// NFC yang bisa dimatikan/dinyalakan seperti oleh mode pesawat.
class SwitchNfc extends NfcService {
  SwitchNfc() : super(FakeNfc());
  bool on = true;
  int starts = 0;
  Completer<ReadResult> _next = Completer<ReadResult>();

  @override
  Future<NfcAvailability> availability() async => on ? NfcAvailability.enabled : NfcAvailability.disabled;
  @override
  Future<ReadResult> read({String prefix = CardPayload.defaultPrefix}) {
    starts++;
    return _next.future;
  }

  @override
  Future<void> stop() async {
    final c = _next;
    _next = Completer<ReadResult>();
    c.future.ignore(); // boleh tidak ada yang menunggu
    if (!c.isCompleted) c.completeError(NfcException('Dibatalkan.'));
  }

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
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  Future<void> pumpHome(WidgetTester tester, ApiClient api, {bool settle = true}) async {
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: EventsScreen(
          key: UniqueKey(),
          session: officer(),
          api: api,
          offlineStore: () => OfflineStore.open(owner: owner),
        ),
      ),
    );
    settle ? await tester.pumpAndSettle() : await tester.pump(const Duration(milliseconds: 50));
  }

  testWidgets('kegiatan yang belum dibuka ikut disiapkan; dipakai setelah offline', (tester) async {
    await pumpHome(
      tester,
      apiOf((r) async {
        if (r.url.path.endsWith('/roster')) return http.Response(jsonEncode(rosterJson()..['event']['id'] = int.parse(r.url.pathSegments[2])), 200);
        if (r.url.queryParameters['status'] == 'upcoming') {
          // halaman 1 = yang paling jauh; yang terdekat di halaman terakhir
          return http.Response(
            jsonEncode(
              r.url.queryParameters['page'] == '2'
                  ? {
                      'last_page': 2,
                      'data': [ev(8, 'Rapat Besok', const Duration(hours: 20), const Duration(hours: 22))],
                    }
                  : {
                      'last_page': 2,
                      'data': [ev(9, 'Bulan Depan', const Duration(days: 30), const Duration(days: 31))],
                    },
            ),
            200,
          );
        }
        return http.Response(jsonEncode({'data': <Object>[]}), 200);
      }),
    );
    expect(find.text('Tidak ada kegiatan berlangsung'), findsOneWidget);
    await pumpHome(tester, apiOf(down));
    expect(find.text('Rapat Besok'), findsOneWidget);
    expect(find.text('Bulan Depan'), findsNothing);
    expect(find.text('Mode offline'), findsOneWidget);
    expect(find.text('Siap offline · 2 peserta'), findsOneWidget);
    expect(find.textContaining('check-in dibuka'), findsOneWidget);
  });

  testWidgets('jaringan menggantung: kegiatan langsung tampil, bukan spinner 10 detik', (tester) async {
    final store = await OfflineStore.open(owner: owner);
    await store.saveEvents([EventSummary.fromJson(ev(7, 'Rapat Pleno', const Duration(hours: -1), const Duration(hours: 1)))]);
    await store.saveRoster(Roster.fromApi(rosterJson()));
    await pumpHome(tester, apiOf(hang), settle: false);
    await tester.pump(const Duration(milliseconds: 50));
    expect(find.text('Mulai scan'), findsOneWidget);
    await tester.pump(const Duration(seconds: 11));
    await tester.pump();
    expect(find.text('Mode offline'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('unduhan peserta gagal: alasannya terlihat', (tester) async {
    await pumpHome(
      tester,
      apiOf((r) async {
        if (r.url.path.endsWith('/roster')) return http.Response(jsonEncode({'message': 'Server Error'}), 500);
        return http.Response(
          jsonEncode({
            'data': [
              if (r.url.queryParameters['status'] == 'ongoing') ev(7, 'Rapat', const Duration(hours: -1), const Duration(hours: 1)),
            ],
          }),
          200,
        );
      }),
    );
    expect(find.text('Belum siap offline: Server Error'), findsOneWidget);
  });

  testWidgets('belum pernah online: pesan jelas & bisa dicoba lagi', (tester) async {
    await pumpHome(tester, apiOf(down));
    expect(find.textContaining('belum ada kegiatan tersimpan'), findsOneWidget);
    expect(find.text('Coba lagi'), findsOneWidget);
  });

  for (final code in [503, 521, 530]) {
    testWidgets('gateway $code dianggap offline', (tester) async {
      await (await OfflineStore.open(owner: owner)).saveEvents([EventSummary.fromJson(ev(7, 'Rapat Pleno', const Duration(hours: -1), const Duration(hours: 1)))]);
      await pumpHome(tester, apiOf((_) async => http.Response('<html>', code)));
      expect(find.text('Mode offline'), findsOneWidget);
    });
  }

  Future<void> pumpMember(WidgetTester tester, Session s, ApiClient api) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);
    await s.save();
    await (await OfflineStore.open(owner: owner)).saveEvents([EventSummary.fromJson(ev(7, 'Rapat Offline', const Duration(hours: -1), const Duration(hours: 1)))]);
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: MemberHomeScreen(session: s, api: api),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('anggota yang bertugas & offline: langsung ke Tugas, sekali saja', (tester) async {
    final s = officer(hasMember: true);
    expect(homeFor(s), isA<MemberHomeScreen>());
    await pumpMember(tester, s, apiOf(down));
    expect(find.text('Rapat Offline').hitTestable(), findsOneWidget);
    expect(find.text('Mulai scan').hitTestable(), findsOneWidget);
    await tester.tap(find.text('Beranda').last);
    await tester.pumpAndSettle();
    expect(find.text('Rapat Offline').hitTestable(), findsNothing); // tidak dipaksa kembali
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('anggota yang bertugas & online: tetap di Beranda', (tester) async {
    await pumpMember(tester, officer(hasMember: true), member.api(member.server()));
    expect(find.text('Mulai scan').hitTestable(), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('anggota biasa tidak punya mode offline', (tester) async {
    await pumpMember(tester, officer(hasMember: true, role: 'member'), apiOf(down));
    expect(find.text('Mode offline'), findsNothing);
    expect(find.text('Rapat Offline'), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });

  Future<SwitchNfc> pumpScan(WidgetTester tester, ApiClient api, SwitchNfc nfc) async {
    final store = await OfflineStore.open(owner: owner);
    await store.saveRoster(Roster.fromApi(rosterJson()));
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: ScanScreen(
          session: officer(),
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
    await tester.pump();
    return nfc;
  }

  testWidgets('NFC dimatikan mode pesawat lalu dinyalakan lagi: layar Scan pulih sendiri', (tester) async {
    final nfc = await pumpScan(tester, apiOf(down), SwitchNfc());
    nfc.on = false;
    await tester.pump(const Duration(seconds: 2));
    await tester.pump();
    expect(find.text('NFC tidak siap'), findsOneWidget);
    nfc.on = true;
    await tester.pump(const Duration(seconds: 2));
    await tester.pump();
    expect(find.text('NFC tidak siap'), findsNothing);
    nfc.tap();
    await tester.pump();
    await tester.pump();
    expect((await OfflineStore.open(owner: owner)).pending(), hasLength(1));
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('setelah satu kegagalan, kartu berikutnya tidak menunggu server', (tester) async {
    var scans = 0;
    final nfc = await pumpScan(
      tester,
      apiOf((r) {
        if (r.url.path.endsWith('/scan')) scans++;
        return hang(r);
      }),
      SwitchNfc(),
    );
    nfc.tap();
    await tester.pump();
    await tester.pump(const Duration(seconds: 4));
    await tester.pump();
    expect(find.textContaining('Tersimpan offline'), findsOneWidget);
    nfc.tap();
    await tester.pump();
    await tester.pump();
    expect(find.textContaining('Sudah absen'), findsWidgets); // langsung, tanpa 4 detik
    expect(scans, 1);
    await tester.pump(const Duration(seconds: 12));
    await tester.pumpWidget(const SizedBox());
  });
}
