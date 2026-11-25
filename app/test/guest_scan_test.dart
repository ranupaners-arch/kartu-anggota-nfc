import 'package:flutter_test/flutter_test.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/widgets/scan_result_card.dart';
import 'package:material_ui/material_ui.dart';

void main() {
  testWidgets('hasil scan tamu (tanpa id anggota) tampil utuh', (tester) async {
    final r = ScanResult.fromJson({
      'status': 'ok',
      'attendance_status': 'hadir',
      'attendance_id': null,
      'message': 'Tamu tercatat.',
      'session': null,
      'member': {'id': null, 'member_no': 'Tamu', 'name': 'Budi Santoso', 'division': 'Universitas Contoh', 'photo_url': null},
    });
    expect(r.ok, isTrue);
    expect(r.memberId, isNull);
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(
          body: ScanResultCard(result: r, token: 't'),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Budi Santoso'), findsOneWidget);
    expect(find.textContaining('Tamu · Universitas Contoh'), findsOneWidget);
  });
}
