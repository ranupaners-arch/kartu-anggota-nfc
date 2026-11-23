import 'package:flutter_test/flutter_test.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/main.dart';
import 'package:kartuanggota/widgets/attendance_chart.dart';
import 'package:material_ui/material_ui.dart';

void main() {
  final months = [(month: DateTime(2026, 9), total: 4, present: 2), (month: DateTime(2026, 10), total: 5, present: 5), (month: DateTime(2026, 11), total: 4, present: 3)];

  testWidgets('grafik per bulan: bawaan bulan terakhir, ketuk batang untuk melihat bulan lain', (tester) async {
    await tester.pumpWidget(
      KartuAnggotaApp(
        home: Scaffold(
          body: Padding(
            padding: const EdgeInsets.all(16),
            child: MonthlyAttendanceChart(months: months),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Nov 2026'), findsOneWidget);
    expect(find.textContaining('75%', findRichText: true), findsOneWidget);
    for (final m in ['Sep', 'Okt', 'Nov']) {
      expect(find.text(m), findsOneWidget);
    }
    // Ketuk kolom pertama (September).
    final first = tester.getTopLeft(find.byType(GestureDetector).first);
    await tester.tapAt(first + const Offset(10, 20));
    await tester.pumpAndSettle();
    expect(find.text('Sep 2026'), findsOneWidget);
    expect(find.textContaining('50%  2/4 kegiatan', findRichText: true), findsOneWidget);
  });

  test('rekap per bulan dibaca dari API (hadir + telat)', () {
    final h = MemberHistory.fromJson({
      'by_status': {'hadir': 1},
      'total': 3,
      'recent': [],
      'monthly': [
        {'month': '2026-10', 'total': 3, 'hadir': 1, 'telat': 1, 'izin': 0, 'sakit': 0, 'alpa': 1},
      ],
    });
    expect(h.monthly.single.month, DateTime(2026, 10));
    expect((h.monthly.single.total, h.monthly.single.present), (3, 2));
    expect(MonthlyAttendanceChart.rate(h.monthly.single), 67);
    expect(MemberHistory.fromJson({'by_status': <String, dynamic>{}, 'total': 0, 'recent': []}).monthly, isEmpty); // server lama
  });
}
