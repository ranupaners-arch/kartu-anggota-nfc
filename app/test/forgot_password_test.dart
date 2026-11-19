import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/login_screen.dart';
import 'package:material_ui/material_ui.dart';

Future<void> openDialog(WidgetTester tester, ApiClient api) async {
  await tester.pumpWidget(
    KartuAnggotaApp(
      home: Builder(
        builder: (context) => Scaffold(
          body: Center(
            child: TextButton(onPressed: () => showForgotPassword(context, api, 'ani@mhs.test'), child: const Text('buka')),
          ),
        ),
      ),
    ),
  );
  await tester.tap(find.text('buka'));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('lupa password: tautan dikirim, email dari kolom login terisi', (tester) async {
    final bodies = <Map<String, dynamic>>[];
    final api = ApiClient(
      baseUrl: 'http://srv',
      client: MockClient((req) async {
        bodies.add(jsonDecode(req.body) as Map<String, dynamic>);
        return http.Response(jsonEncode({'message': 'Bila email itu terdaftar, tautan sudah dikirim.'}), 200);
      }),
    );
    await openDialog(tester, api);
    await tester.tap(find.text('Kirim tautan'));
    await tester.pumpAndSettle();
    expect(bodies.single, {'email': 'ani@mhs.test'});
    expect(find.text('Bila email itu terdaftar, tautan sudah dikirim.'), findsOneWidget);
    expect(find.text('Selesai'), findsOneWidget);
  });

  testWidgets('lupa password: belum aktif di server → pesan hubungi pengurus', (tester) async {
    final api = ApiClient(
      baseUrl: 'http://srv',
      client: MockClient((_) async => http.Response(jsonEncode({'message': 'Reset password lewat email belum aktif. Hubungi pengurus.'}), 503)),
    );
    await openDialog(tester, api);
    await tester.tap(find.text('Kirim tautan'));
    await tester.pumpAndSettle();
    expect(find.text('Reset password lewat email belum aktif. Hubungi pengurus.'), findsOneWidget);
    expect(find.text('Kirim tautan'), findsOneWidget); // masih bisa dicoba lagi
  });
}
