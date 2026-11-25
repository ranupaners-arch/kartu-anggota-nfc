import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/app/session.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/notify/android_notices.dart';
import 'package:kartuanggota/notify/phone_notices.dart';
import 'package:kartuanggota/screens/member/member_home_screen.dart';
import 'package:kartuanggota/screens/member/notifications_page.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'flutter_test_config.dart';
import 'member_app_test.dart' as member show api, server;

final now = DateTime.utc(2026, 11, 24, 10);
FakeNotices get fake => PhoneNotices.platform as FakeNotices;

Map<String, dynamic> notice(String id, {Duration ago = const Duration(hours: 1)}) => {'id': id, 'type': 'announcement', 'title': 'Judul $id', 'body': null, 'at': now.subtract(ago).toIso8601String()};

ApiClient Function(Session) apiWith(List<Map<String, dynamic>> list, {int code = 200, Future<void> Function()? before}) =>
    (s) => ApiClient(
      baseUrl: s.baseUrl,
      client: MockClient((_) async {
        await before?.call();
        return http.Response(jsonEncode({'data': list}), code);
      }),
    )..token = s.token;

Future<void> signIn({int id = 1, String role = 'member', bool hasMember = true}) => Session(baseUrl: 'http://srv', token: 't', userName: 'Ani', role: role, hasMember: hasMember, userId: id).save();

Future<int> check(List<Map<String, dynamic>> list, {int code = 200, Future<void> Function()? before}) => PhoneNotices.check(
  client: apiWith(list, code: code, before: before),
  now: now,
);

void main() {
  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    await signIn();
  });

  test('bawaan menyala: terjadwal & izin diminta sekali saja, ditolak pun tetap terjadwal', () async {
    fake.allow = false;
    await PhoneNotices.attach(() {});
    await PhoneNotices.attach(() {});
    expect((fake.scheduled, fake.asked), (true, 1));
  });

  test('pertama kali tidak membanjiri; selanjutnya hanya yang baru, sekali saja', () async {
    expect(await check([notice('a'), notice('b')]), 0);
    expect(await check([notice('c'), notice('a'), notice('b')]), 1);
    expect(await check([notice('c'), notice('a')]), 0);
    expect(fake.shown, ['c']);
  });

  test('banyak sekaligus diringkas; yang lama atau sudah dibaca di app dilewati', () async {
    await check([]);
    expect(await check([for (var i = 0; i < 5; i++) notice('n$i')]), 5);
    expect(fake.shown, ['ringkasan:5']);
    expect(await check([notice('tua', ago: const Duration(days: 4))]), 0);
    await NoticeSeen.save(now.subtract(const Duration(minutes: 30)));
    expect(await check([notice('dibaca'), notice('baru', ago: const Duration(minutes: 5))]), 1);
    expect(fake.shown.last, 'baru');
  });

  for (final code in [401, 422, 503]) {
    test('HTTP $code: diam, sesi tetap, dicoba lagi', () async {
      await check([]);
      expect(await check([notice('a')], code: code), 0);
      expect(await Session.load(), isNotNull);
      expect(await check([notice('a')]), 1);
    });
  }

  test('keluar atau ganti akun selagi menunggu server: tidak menampilkan apa pun', () async {
    await check([]);
    expect(await check([notice('a')], before: Session.clear), 0);
    await signIn();
    await check([]);
    expect(await check([notice('b')], before: () => signIn(id: 2)), 0);
    expect(fake.shown, isEmpty);
  });

  test('keluar menghentikan & menghapus notifikasi yang tampil', () async {
    await PhoneNotices.attach(() {});
    await Session.clear();
    await Future<void>.delayed(Duration.zero);
    expect((fake.scheduled, fake.cancelled), (false, 1));
    expect(await check([notice('a')]), 0);
  });

  test('petugas tanpa data anggota tidak memeriksa; anggota bertugas tetap', () async {
    await signIn(role: 'scanner', hasMember: false);
    var calls = 0;
    await check([], before: () async => calls++);
    expect(calls, 0);
    await signIn(role: 'scanner');
    await check([], before: () async => calls++);
    expect(calls, 2); // notifikasi & kegiatan
  });

  MemberEvent ev(int id, Duration inTime, {String? status, String? excuse, bool closed = false}) =>
      MemberEvent(id: id, title: 'K$id', startsAt: now.add(inTime), checkinClosesAt: now.add(inTime), myStatus: status, excuseStatus: excuse, closed: closed);
  Future<void> sync(List<MemberEvent> e, {DateTime? at}) => PhoneNotices.syncReminders('http://srv|1', e, now: at ?? now);

  test('alarm H-24 jam & H-1 jam; yang hadir, izin, ditutup, atau lewat tidak', () async {
    await sync([
      ev(1, const Duration(days: 3)),
      ev(2, const Duration(hours: 5)),
      ev(3, const Duration(days: 2), status: 'hadir'),
      ev(4, const Duration(days: 2), excuse: 'pending'),
      ev(5, const Duration(days: 2), closed: true),
      ev(6, const Duration(hours: -1)),
    ]);
    expect(fake.alarms.keys, unorderedEquals(['reminder:1', 'soon:1', 'soon:2']));
    expect(fake.alarms['reminder:1'], now.add(const Duration(days: 2)));
  });

  test('jadwal berubah dipasang ulang; kegiatan hilang atau sudah izin dibatalkan', () async {
    await sync([ev(1, const Duration(days: 3)), ev(2, const Duration(days: 3))]);
    await sync([ev(1, const Duration(days: 4)), ev(2, const Duration(days: 3), excuse: 'approved')]);
    expect(fake.alarms, {'reminder:1': now.add(const Duration(days: 3)), 'soon:1': now.add(const Duration(days: 4, hours: -1))});
    await sync([]);
    expect(fake.alarms, isEmpty);
  });

  test('pengingat yang sudah dialarmkan HP tidak diulang pemeriksaan server', () async {
    await check([]);
    await sync([ev(9, const Duration(days: 2))]);
    final later = now.add(const Duration(hours: 30));
    await sync([ev(9, const Duration(days: 2))], at: later); // H-24 sudah lewat
    expect(await check([notice('reminder:9'), notice('a')]), 1);
    expect(fake.shown, ['a']);
  });

  test('kegiatan mendadak (<24 jam) tetap diingatkan lewat server', () async {
    await check([]);
    await sync([ev(9, const Duration(hours: 20))]);
    expect(await check([notice('reminder:9')]), 1);
  });

  test('keluar akun menghapus alarm', () async {
    await sync([ev(1, const Duration(days: 3))]);
    await Session.clear();
    await Future<void>.delayed(Duration.zero);
    expect(fake.alarms, isEmpty);
    expect((await SharedPreferences.getInstance()).getStringList('notif_alarm@http://srv|1'), isNull);
  });

  test('plugin rusak: keluar akun & buka layar tetap jalan', () async {
    fake.broken = true;
    await PhoneNotices.attach(() {});
    await PhoneNotices.signedOut('http://srv|1');
  });

  test('id notifikasi tetap & dalam rentang Android', () {
    expect(AndroidNotices.idOf('due:1'), AndroidNotices.idOf('due:1'));
    expect({for (var i = 0; i < 2000; i++) AndroidNotices.idOf('due:$i')}, hasLength(2000));
    expect(AndroidNotices.idOf('x' * 500), inInclusiveRange(0, 0x7fffffff));
  });

  testWidgets('ketuk notifikasi membuka halaman Notifikasi & membersihkan bilah status', (tester) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);
    final s = (await Session.load())!;
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: MemberHomeScreen(session: s, api: member.api(member.server())),
      ),
    );
    await tester.pumpAndSettle();
    fake.onTap!();
    await tester.pumpAndSettle();
    expect(find.byType(NotificationsPage), findsOneWidget);
    expect((fake.cleared, fake.cancelled), (1, 0)); // alarm pengingat tidak ikut terhapus
    await tester.pumpWidget(const SizedBox());
  });
}
