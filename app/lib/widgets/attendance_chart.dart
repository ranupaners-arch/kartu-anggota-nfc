import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

/// Lebar jalur label sumbu kiri.
const _gutter = 36.0;

const _bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

/// Grafik batang persentase kehadiran (hadir + telat) per bulan. Satu seri → satu warna, tanpa legenda
/// (judul kartu sudah menamainya). Batang tipis berujung bulat menempel ke garis dasar; garis bantu
/// 0/50/100% samar. Ketuk batang untuk melihat angkanya (bawaan: bulan terakhir).
class MonthlyAttendanceChart extends StatefulWidget {
  const MonthlyAttendanceChart({super.key, required this.months, this.height = 140});

  final List<({DateTime month, int total, int present})> months;
  final double height;

  static String monthLabel(DateTime m, {bool year = false}) => year ? '${_bulan[m.month - 1]} ${m.year}' : _bulan[m.month - 1];

  static int rate(({DateTime month, int total, int present}) m) => m.total == 0 ? 0 : (m.present * 100 / m.total).round();

  @override
  State<MonthlyAttendanceChart> createState() => _MonthlyAttendanceChartState();
}

class _MonthlyAttendanceChartState extends State<MonthlyAttendanceChart> {
  late int _selected = widget.months.length - 1;

  @override
  void didUpdateWidget(MonthlyAttendanceChart old) {
    super.didUpdateWidget(old);
    if (_selected >= widget.months.length) _selected = widget.months.length - 1;
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    final dark = theme.brightness == Brightness.dark;
    final bar = dark ? const Color(0xFFA5B4FC) : const Color(0xFF4F46E5); // indigo kartu, terang di mode gelap
    final barDim = bar.withValues(alpha: 0.35);
    final grid = theme.colorScheme.border;
    final months = widget.months;
    if (months.isEmpty) return const SizedBox.shrink();
    final sel = months[_selected.clamp(0, months.length - 1)];

    // Pembaca layar: tabel ringkas seluruh bulan.
    final summary = months.map((m) => '${MonthlyAttendanceChart.monthLabel(m.month, year: true)} ${MonthlyAttendanceChart.rate(m)} persen, ${m.present} dari ${m.total} kegiatan').join('; ');

    return Semantics(
      label: 'Grafik kehadiran per bulan. $summary',
      child: ExcludeSemantics(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Nilai bulan terpilih (teks memakai warna teks, bukan warna batang).
            Wrap(
              alignment: WrapAlignment.spaceBetween,
              crossAxisAlignment: WrapCrossAlignment.end,
              spacing: 8,
              children: [
                Text(MonthlyAttendanceChart.monthLabel(sel.month, year: true), style: theme.textTheme.muted),
                Text.rich(
                  TextSpan(
                    children: [
                      TextSpan(text: '${MonthlyAttendanceChart.rate(sel)}%', style: theme.textTheme.large),
                      TextSpan(text: '  ${sel.present}/${sel.total} kegiatan', style: theme.textTheme.muted.copyWith(fontSize: 12)),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Label sumbu (0/50/100%) di jalur sendiri agar tidak tertimpa batang.
                SizedBox(
                  width: _gutter,
                  height: widget.height,
                  child: Stack(
                    clipBehavior: Clip.none,
                    children: [
                      for (final f in [0.0, 0.5, 1.0])
                        Positioned(
                          right: 6,
                          bottom: f * (widget.height - 1) - 6,
                          child: Text('${(f * 100).round()}%', style: theme.textTheme.muted.copyWith(fontSize: 10, height: 1.2)),
                        ),
                    ],
                  ),
                ),
                Expanded(
                  child: SizedBox(
                    height: widget.height,
                    child: LayoutBuilder(
                      builder: (context, box) {
                        // Lebar batang tipis: ±40% kolom, maks. 28 px.
                        final col = box.maxWidth / months.length;
                        final barW = (col * 0.4).clamp(6.0, 28.0);
                        return Stack(
                          children: [
                            // Garis bantu 0 / 50 / 100%.
                            for (final f in [0.0, 0.5, 1.0])
                              Positioned(
                                left: 0,
                                right: 0,
                                bottom: f * (box.maxHeight - 1),
                                child: Container(height: 1, color: f == 0 ? grid : grid.withValues(alpha: 0.5)),
                              ),
                            Row(
                              crossAxisAlignment: CrossAxisAlignment.end,
                              children: [
                                for (var i = 0; i < months.length; i++)
                                  Expanded(
                                    // Area ketuk selebar kolom & setinggi grafik (lebih besar dari batangnya).
                                    child: GestureDetector(
                                      behavior: HitTestBehavior.opaque,
                                      onTap: () => setState(() => _selected = i),
                                      child: Align(
                                        alignment: Alignment.bottomCenter,
                                        child: AnimatedContainer(
                                          duration: const Duration(milliseconds: 250),
                                          width: barW,
                                          height: (box.maxHeight * MonthlyAttendanceChart.rate(months[i]) / 100).clamp(2.0, box.maxHeight),
                                          decoration: BoxDecoration(
                                            color: i == _selected ? bar : barDim,
                                            borderRadius: const BorderRadius.vertical(top: Radius.circular(4)),
                                          ),
                                        ),
                                      ),
                                    ),
                                  ),
                              ],
                            ),
                          ],
                        );
                      },
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 6),
            Row(
              children: [
                const SizedBox(width: _gutter),
                for (var i = 0; i < months.length; i++)
                  Expanded(
                    child: Text(
                      MonthlyAttendanceChart.monthLabel(months[i].month),
                      textAlign: TextAlign.center,
                      maxLines: 1,
                      overflow: TextOverflow.clip,
                      style: theme.textTheme.muted.copyWith(fontSize: 11, fontWeight: i == _selected ? FontWeight.w700 : FontWeight.w400),
                    ),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
