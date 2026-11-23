import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/screens/member/profile_change_page.dart';
import 'package:material_ui/material_ui.dart';

Map<String, dynamic> status({Map<String, dynamic>? pending, Map<String, dynamic>? last}) => {
  'current': {'name': 'Budi Santoso', 'phone': '0811111', 'has_photo': false},
  'pending': pending,
  'last_decision': last,
};

void main() {
  Future<void> pump(WidgetTester tester, MockClient client) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(
          body: ProfileChangePage(
            api: ApiClient(baseUrl: 'http://srv', client: client)..token = 't',
            pickPhoto: () async => (name: 'wajah.jpg', bytes: [1, 2, 3]),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('ajukan no. HP & foto baru → terkirim, tampil menunggu persetujuan', (tester) async {
    String? body;
    await pump(
      tester,
      MockClient((req) async {
        if (req.method == 'POST') {
          body = latin1.decode(req.bodyBytes);
          return http.Response(jsonEncode(status(pending: {'name': null, 'phone': '08222222', 'has_photo': true, 'status': 'pending', 'created_at': '2026-11-23T03:00:00Z'})), 201);
        }
        return http.Response(jsonEncode(status()), 200);
      }),
    );
    final fields = find.byType(EditableText);
    expect((tester.widget<EditableText>(fields.at(0))).controller.text, 'Budi Santoso'); // terisi data saat ini
    await tester.enterText(fields.at(1), '08222222');
    await tester.tap(find.text('Pilih foto baru'));
    await tester.pumpAndSettle();
    expect(find.text('wajah.jpg'), findsOneWidget);
    await tester.ensureVisible(find.text('Kirim pengajuan'));
    await tester.tap(find.text('Kirim pengajuan'));
    await tester.pumpAndSettle();

    expect(body, contains('name="phone"\r\n\r\n08222222'));
    expect(body, isNot(contains('name="name"'))); // nama tidak berubah → tidak dikirim
    expect(body, contains('filename="wajah.jpg"'));
    expect(find.text('Pengajuan terkirim'), findsOneWidget);
    expect(find.text('Menunggu persetujuan pengurus'), findsOneWidget);
    expect(find.textContaining('no. HP → 08222222, foto baru'), findsOneWidget);
  });

  testWidgets('tanpa perubahan → pesan, tidak mengirim', (tester) async {
    var posts = 0;
    await pump(
      tester,
      MockClient((req) async {
        if (req.method == 'POST') posts++;
        return http.Response(jsonEncode(status()), 200);
      }),
    );
    await tester.ensureVisible(find.text('Kirim pengajuan'));
    await tester.tap(find.text('Kirim pengajuan'));
    await tester.pumpAndSettle();
    expect(find.text('Belum ada data yang diubah.'), findsOneWidget);
    expect(posts, 0);
  });

  testWidgets('keputusan terakhir ditolak tampil beserta catatan', (tester) async {
    await pump(
      tester,
      MockClient((req) async => http.Response(jsonEncode(status(last: {'status': 'rejected', 'review_note': 'Foto buram', 'has_photo': false, 'reviewed_at': '2026-11-22T03:00:00Z'})), 200)),
    );
    expect(find.text('Perubahan terakhir ditolak'), findsOneWidget);
    expect(find.text('Catatan pengurus: Foto buram'), findsOneWidget);
  });
}
