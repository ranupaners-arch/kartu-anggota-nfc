import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:kartuanggota/app/server_config.dart';
import 'package:kartuanggota/app/session.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/connect_screen.dart';
import 'package:kartuanggota/screens/events_screen.dart';
import 'package:kartuanggota/screens/login_screen.dart';
import 'package:kartuanggota/screens/splash_screen.dart';
import 'package:kartuanggota/screens/welcome_screen.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// App sungguhan dari awal (tanpa `home`), tautan sistem diganti stream kosong.
Future<void> start(WidgetTester tester) async {
  await tester.pumpWidget(KartuAnggotaApp(links: const Stream<Uri>.empty()));
  await tester.pump();
}

void main() {
  testWidgets('splash: latar hitam, logo berputar 3D lalu berhenti menghadap depan', (tester) async {
    SharedPreferences.setMockInitialValues({});
    await start(tester);
    expect(find.byType(SplashScreen<(Session?, ServerConfig?)>), findsOneWidget);
    expect(tester.widget<Scaffold>(find.byType(Scaffold)).backgroundColor, splashBlack);

    Matrix4 transform() => tester.widget<Transform>(find.ancestor(of: find.byType(BrandLogo), matching: find.byType(Transform)).first).transform;
    await tester.pump(const Duration(milliseconds: 500));
    expect(transform().getRotation().entry(0, 0), lessThan(0.99), reason: 'sedang berputar');
    await tester.pumpAndSettle();
    expect(find.byType(WelcomeScreen), findsOneWidget);
  });

  testWidgets('belum ada server: sambutan → Masuk → Hubungkan organisasi', (tester) async {
    SharedPreferences.setMockInitialValues({});
    await start(tester);
    await tester.pumpAndSettle();
    expect(find.text('Kartu Anggota'), findsOneWidget);
    expect(find.text('Masuk'), findsOneWidget);
    await tester.tap(find.text('Masuk'));
    await tester.pumpAndSettle();
    expect(find.byType(ConnectScreen), findsOneWidget);
  });

  testWidgets('server sudah diketahui: nama organisasi tampil, Masuk → layar Masuk', (tester) async {
    SharedPreferences.setMockInitialValues({});
    await const ServerConfig(url: 'https://absen.hmps.id', name: 'HMPS').save();
    await start(tester);
    await tester.pumpAndSettle();
    expect(find.text('HMPS'), findsOneWidget);
    await tester.tap(find.text('Masuk'));
    await tester.pumpAndSettle();
    expect(find.byType(LoginScreen), findsOneWidget);
  });

  testWidgets('sudah login: splash langsung ke beranda, tanpa sambutan', (tester) async {
    SharedPreferences.setMockInitialValues({});
    await Session(baseUrl: 'http://srv', token: 't', userName: 'Petugas', role: 'scanner').save();
    await start(tester);
    await tester.pump(const Duration(seconds: 2));
    await tester.pump(const Duration(milliseconds: 400));
    expect(find.byType(EventsScreen), findsOneWidget);
    expect(find.byType(WelcomeScreen), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });
}
