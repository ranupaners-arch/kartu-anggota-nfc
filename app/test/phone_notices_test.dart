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
import 'package:kartuanggota/screens/member/phone_notices_page.dart';
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

  test('mati secara bawaan; menyalakan meminta izin & menjadwalkan', () async {
    expect(await PhoneNotices.enabled(), isFalse);
    expect(await check([notice('a')]), 0);
    fake.allow = false;
    expect((await PhoneNotices.enable(), fake.scheduled), (false, false));
    fake.allow = true;
    expect((await PhoneNotices.enable(), fake.scheduled, await PhoneNotices.enabled()), (true, true, true));
    await PhoneNotices.disable();
    expect((await PhoneNotices.enabled(), fake.scheduled), (false, false));
  });

  test('pertama kali tidak membanjiri; selanjutnya hanya yang baru, sekali saja', () async {
    await PhoneNotices.enable();
    expect(await check([notice('a'), notice('b')]), 0);
    expect(await check([notice('c'), notice('a'), notice('b')]), 1);
    expect(await check([notice('c'), notice('a')]), 0);
    expect(fake.shown, ['c']);
  });

  test('banyak sekaligus diringkas; yang lama atau sudah dibaca di app dilewati', () async {
    await PhoneNotices.enable();
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
      await PhoneNotices.enable();
      await check([]);
      expect(await check([notice('a')], code: code), 0);
      expect(await Session.load(), isNotNull);
      expect(await check([notice('a')]), 1);
    });
  }

  test('keluar atau ganti akun selagi menunggu server: tidak menampilkan apa pun', () async {
    await PhoneNotices.enable();
    await check([]);
    expect(await check([notice('a')], before: Session.clear), 0);
    await signIn();
    await PhoneNotices.enable();
    await check([]);
    expect(await check([notice('b')], before: () => signIn(id: 2)), 0);
    expect(fake.shown, isEmpty);
  });

  test('pengaturan per akun; keluar menghentikan & menghapus notifikasi yang tampil', () async {
    await PhoneNotices.enable();
    await Session.clear();
    await Future<void>.delayed(Duration.zero);
    expect((fake.scheduled, fake.cancelled), (false, 1));
    await signIn(id: 2);
    expect(await PhoneNotices.enabled(), isFalse);
    await signIn();
    expect(await PhoneNotices.enabled(), isTrue); // pilihan akun ini diingat
  });

  test('petugas tanpa data anggota tidak memeriksa; anggota bertugas tetap', () async {
    await signIn(role: 'scanner', hasMember: false);
    await PhoneNotices.enable();
    var calls = 0;
    await check([], before: () async => calls++);
    expect(calls, 0);
    await signIn(role: 'scanner');
    await check([], before: () async => calls++);
    expect(calls, 1);
  });

  test('plugin rusak: keluar akun & buka layar tetap jalan', () async {
    await PhoneNotices.enable();
    fake.broken = true;
    await PhoneNotices.attach(() {});
    await PhoneNotices.signedOut('http://srv|1');
  });

  test('id notifikasi tetap & dalam rentang Android', () {
    expect(AndroidNotices.idOf('due:1'), AndroidNotices.idOf('due:1'));
    expect({for (var i = 0; i < 2000; i++) AndroidNotices.idOf('due:$i')}, hasLength(2000));
    expect(AndroidNotices.idOf('x' * 500), inInclusiveRange(0, 0x7fffffff));
  });

  testWidgets('halaman pengaturan: ditolak → petunjuk; diizinkan → menyala', (tester) async {
    fake.allow = false;
    await tester.pumpWidget(const KartuAnggotaApp(home: Scaffold(body: PhoneNoticesPage())));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Nyalakan'));
    await tester.pumpAndSettle();
    expect(find.textContaining('Izin notifikasi belum diberikan'), findsOneWidget);
    fake.allow = true;
    await tester.tap(find.text('Nyalakan'));
    await tester.pumpAndSettle();
    expect(find.text('Notifikasi HP menyala'), findsOneWidget);
    await tester.tap(find.text('Matikan'));
    await tester.pumpAndSettle();
    expect(find.text('Notifikasi HP mati'), findsOneWidget);
  });

  testWidgets('ketuk notifikasi membuka halaman Notifikasi & membersihkan bilah status', (tester) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);
    await PhoneNotices.enable();
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
    expect(fake.cancelled, 1);
    await tester.pumpWidget(const SizedBox());
  });
}
