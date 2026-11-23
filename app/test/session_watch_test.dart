import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/app/server_config.dart';
import 'package:kartuanggota/app/session.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/events_screen.dart';
import 'package:kartuanggota/screens/login_screen.dart';
import 'package:kartuanggota/screens/member/member_home_screen.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Server tiruan: /api/me menjawab [me] (atau 401 bila null); endpoint lain kosong.
ApiClient api(Map<String, dynamic>? me) => ApiClient(
  baseUrl: 'http://srv',
  client: MockClient((req) async {
    if (req.url.path == '/api/me') return me == null ? http.Response(jsonEncode({'message': 'Unauthenticated.'}), 401) : http.Response(jsonEncode(me), 200);
    return http.Response(jsonEncode({'data': []}), 200);
  }),
)..token = 't';

void main() {
  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    await const ServerConfig(url: 'http://srv', name: 'HMPS').save();
  });

  final petugas = Session(baseUrl: 'http://srv', token: 't', userName: 'Sari', role: 'scanner');

  testWidgets('peran petugas dicabut jadi anggota → langsung pindah ke tampilan anggota', (tester) async {
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: EventsScreen(session: petugas, api: api({'name': 'Sari', 'role': 'member'})),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byType(MemberHomeScreen), findsOneWidget);
    expect(find.byType(EventsScreen), findsNothing);
    expect((await Session.load())!.role, 'member'); // tersimpan untuk pembukaan berikutnya
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('sesi dicabut (401) → layar Masuk dengan penjelasan', (tester) async {
    await petugas.save();
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: EventsScreen(session: petugas, api: api(null)),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byType(LoginScreen), findsOneWidget);
    expect(find.textContaining('Sesi Anda berakhir'), findsOneWidget);
    expect(await Session.load(), isNull);
  });

  testWidgets('peran sama → tetap di layar yang sama', (tester) async {
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: EventsScreen(session: petugas, api: api({'name': 'Sari', 'role': 'scanner'})),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byType(EventsScreen), findsOneWidget);
  });
}
