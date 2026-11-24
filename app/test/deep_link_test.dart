import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:kartuanggota/app/session.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/connect_screen.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  late StreamController<Uri> links;
  setUp(() => links = StreamController<Uri>());
  tearDown(() => links.close());

  Future<void> open(WidgetTester tester, String link) async {
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: const Scaffold(body: Text('Beranda')),
        links: links.stream,
      ),
    );
    links.add(Uri.parse(link));
    await tester.pumpAndSettle();
  }

  testWidgets('belum masuk: tautan QR penghubung membuka layar Hubungkan dengan alamat terisi', (tester) async {
    SharedPreferences.setMockInitialValues({});
    await open(tester, 'kartuanggota://connect?server=https%3A%2F%2Fabsen.hmps.id&name=HMPS');
    expect(find.byType(ConnectScreen), findsOneWidget);
    expect(tester.widget<ConnectScreen>(find.byType(ConnectScreen)).initialUrl, 'https://absen.hmps.id');
    expect(find.text('https://absen.hmps.id'), findsOneWidget);
  });

  testWidgets('sudah masuk ke organisasi lain: tidak memutus sesi, tampilkan penjelasan', (tester) async {
    SharedPreferences.setMockInitialValues({});
    await Session(baseUrl: 'https://absen.lain.id', token: 't', userName: 'Budi', role: 'scanner').save();
    await open(tester, 'kartuanggota://connect?server=https%3A%2F%2Fabsen.hmps.id&name=HMPS');
    expect(find.byType(ConnectScreen), findsNothing);
    expect(find.text('Sedang masuk ke organisasi lain'), findsOneWidget);
    await tester.tap(find.text('Mengerti'));
    await tester.pumpAndSettle();
    expect(find.text('Beranda'), findsOneWidget);
  });

  testWidgets('tautan lain diabaikan', (tester) async {
    SharedPreferences.setMockInitialValues({});
    await open(tester, 'kartuanggota://lain?server=x');
    expect(find.text('Beranda'), findsOneWidget);
  });
}
