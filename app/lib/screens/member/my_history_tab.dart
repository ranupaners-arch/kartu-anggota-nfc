import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/format.dart';
import '../../app/pdf.dart';
import '../../widgets/attendance_chart.dart';
import '../../widgets/badges.dart';
import '../../widgets/ui.dart';

Future<String?> _saveAndOpenPdf(List<int> bytes) => saveAndOpenPdf(bytes, fileName: 'rapor-kehadiran.pdf');

/// Ringkasan & riwayat kehadiran anggota periode berjalan, dengan unduhan rapor PDF.
class MyHistoryTab extends StatefulWidget {
  const MyHistoryTab({super.key, required this.api, this.openPdf = _saveAndOpenPdf});

  final ApiClient api;

  /// Untuk tes.
  final Future<String?> Function(List<int> bytes) openPdf;

  @override
  State<MyHistoryTab> createState() => _MyHistoryTabState();
}

class _MyHistoryTabState extends State<MyHistoryTab> {
  late Future<MemberHistory> _history = widget.api.myHistory();
  late Future<Achievements?> _achievements = _loadAchievements();

  /// Poin & lencana (opsional: server lama tanpa endpoint ini → disembunyikan).
  Future<Achievements?> _loadAchievements() => widget.api.myAchievements().then<Achievements?>((a) => a).catchError((_) => null);
  bool _downloading = false;
  String? _downloadError;

  Future<void> _downloadReport() async {
    setState(() {
      _downloading = true;
      _downloadError = null;
    });
    try {
      final bytes = await widget.api.downloadBytes('${widget.api.baseUrl}/api/me/report-card');
      final err = await widget.openPdf(bytes);
      if (mounted) setState(() => _downloadError = err);
    } on ApiException catch (e) {
      if (mounted) setState(() => _downloadError = e.message);
    } catch (_) {
      if (mounted) setState(() => _downloadError = 'Gagal mengunduh rapor. Periksa koneksi.');
    } finally {
      if (mounted) setState(() => _downloading = false);
    }
  }

  Future<void> _refresh() async {
    setState(() {
      _history = widget.api.myHistory();
      _achievements = _loadAchievements();
    });
    await _history.catchError((_) => const MemberHistory(byStatus: {}, total: 0));
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return RefreshIndicator(
      onRefresh: _refresh,
      child: FutureBuilder<MemberHistory>(
        future: _history,
        builder: (context, snap) {
          final children = <Widget>[];
          if (snap.connectionState != ConnectionState.done) {
            children.add(
              const Padding(
                padding: EdgeInsets.all(32),
                child: Center(child: CircularProgressIndicator()),
              ),
            );
          } else if (snap.hasError) {
            children.add(ErrorBanner(snap.error is ApiException ? (snap.error as ApiException).message : 'Tidak bisa memuat riwayat. Tarik untuk mencoba lagi.'));
          } else {
            final h = snap.data!;
            if (h.atRisk) {
              children.addAll([
                ShadAlert(
                  icon: const Icon(LucideIcons.triangleAlert),
                  title: const Text('Kehadiran Anda perlu perhatian'),
                  description: Text(
                    h.alpaStreak >= 3
                        ? 'Anda tercatat Alpa ${h.alpaStreak}× berturut-turut. Ajukan izin bila berhalangan, atau hubungi pengurus.'
                        : 'Persentase kehadiran Anda masih rendah. Ajukan izin bila berhalangan, atau hubungi pengurus.',
                  ),
                ),
                const SizedBox(height: 12),
              ]);
            }
            // Sama dengan portal web: persen besar, lima kotak status, tombol rapor.
            final left = <Widget>[], right = <Widget>[];
            left.add(
              SectionCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text('Kehadiran${h.term != null ? ' periode ${h.term}' : ''}', style: theme.textTheme.muted),
                    Text(h.rate == null ? '–' : '${h.rate!.toStringAsFixed(h.rate! % 1 == 0 ? 0 : 1)}%', style: theme.textTheme.h2),
                    const SizedBox(height: 12),
                    Row(
                      spacing: 6,
                      children: [
                        for (final s in ['hadir', 'telat', 'izin', 'sakit', 'alpa'])
                          Expanded(
                            child: Container(
                              padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 2),
                              decoration: BoxDecoration(color: theme.colorScheme.muted, borderRadius: BorderRadius.circular(8)),
                              child: Column(
                                children: [
                                  Text(statusLabel[s]!, style: theme.textTheme.muted.copyWith(fontSize: 11), maxLines: 1, overflow: TextOverflow.ellipsis),
                                  Text('${h.byStatus[s] ?? 0}', style: theme.textTheme.large),
                                ],
                              ),
                            ),
                          ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    FlexButton(
                      variant: ShadButtonVariant.outline,
                      icon: LucideIcons.fileDown,
                      enabled: !_downloading,
                      onPressed: _downloadReport,
                      label: _downloading ? 'Mengunduh rapor…' : 'Unduh rapor PDF',
                    ),
                  ],
                ),
              ),
            );
            if (_downloadError != null) left.addAll([const SizedBox(height: 8), ErrorBanner(_downloadError!)]);
            left.add(const SizedBox(height: 12));
            if (h.monthly.isNotEmpty) {
              left.add(
                SectionCard(
                  title: 'Kehadiran per bulan',
                  icon: LucideIcons.chartColumn,
                  child: MonthlyAttendanceChart(months: h.monthly),
                ),
              );
              left.add(const SizedBox(height: 12));
            }
            if (h.dues.isNotEmpty) {
              const label = {'unpaid': 'Belum lunas', 'paid': 'Lunas', 'waived': 'Dibebaskan'};
              left.add(
                SectionCard(
                  title: 'Iuran',
                  icon: LucideIcons.wallet,
                  description: h.duesUnpaid > 0 ? 'Belum lunas: ${rupiah(h.duesUnpaid)} — bayar ke bendahara.' : 'Semua iuran sudah beres.',
                  child: Column(
                    children: [
                      for (final d in h.dues)
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 4),
                          child: Wrap(
                            alignment: WrapAlignment.spaceBetween,
                            crossAxisAlignment: WrapCrossAlignment.center,
                            spacing: 8,
                            runSpacing: 4,
                            children: [
                              Text('${d.title}${d.dueOn == null ? '' : ' · jatuh tempo ${fmtDate(d.dueOn!)}'}'),
                              Wrap(
                                crossAxisAlignment: WrapCrossAlignment.center,
                                spacing: 8,
                                runSpacing: 4,
                                children: [
                                  Text(rupiah(d.amount), style: theme.textTheme.small),
                                  StatusBadge(status: d.status, label: label[d.status] ?? d.status),
                                ],
                              ),
                            ],
                          ),
                        ),
                    ],
                  ),
                ),
              );
              left.add(const SizedBox(height: 12));
            }
            if (h.fines.isNotEmpty) {
              const label = {'unpaid': 'Belum lunas', 'paid': 'Lunas', 'waived': 'Dibebaskan'};
              left.add(
                SectionCard(
                  title: 'Denda absen pakai QR',
                  description: 'Belum lunas: ${rupiah(h.finesUnpaid)} — bayar ke pengurus. Bawa kartu agar tidak didenda.',
                  child: Padding(
                    padding: EdgeInsets.zero,
                    child: Column(
                      children: [
                        for (final f in h.fines)
                          Padding(
                            padding: const EdgeInsets.symmetric(vertical: 4),
                            child: Row(
                              children: [
                                Expanded(child: Text('${f.event}${f.date == null ? '' : ' · ${fmtDate(f.date!)}'}', overflow: TextOverflow.ellipsis, maxLines: 2)),
                                const SizedBox(width: 8),
                                Text(rupiah(f.amount), style: theme.textTheme.small),
                                const SizedBox(width: 8),
                                StatusBadge(status: f.status, label: label[f.status] ?? f.status),
                              ],
                            ),
                          ),
                      ],
                    ),
                  ),
                ),
              );
              left.add(const SizedBox(height: 12));
            }
            right.add(
              FutureBuilder<Achievements?>(
                future: _achievements,
                builder: (context, a) => a.data == null
                    ? const SizedBox.shrink()
                    : Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: SectionCard(
                          title: 'Poin & lencana',
                          icon: LucideIcons.award,
                          child: BadgesView(data: a.data!),
                        ),
                      ),
              ),
            );
            right.add(
              SectionCard(
                title: 'Riwayat terbaru',
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (h.recent.isEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 8),
                        child: Text('Belum ada riwayat kehadiran.', style: theme.textTheme.muted),
                      ),
                    for (var k = 0; k < h.recent.length; k++) ...[
                      if (k > 0) Divider(height: 1, color: theme.colorScheme.border),
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 10),
                        child: Row(
                          children: [
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(h.recent[k].event, style: theme.textTheme.small, maxLines: 2, overflow: TextOverflow.ellipsis),
                                  if (h.recent[k].date != null) Text(fmtDate(h.recent[k].date!), style: theme.textTheme.muted.copyWith(fontSize: 12)),
                                ],
                              ),
                            ),
                            const SizedBox(width: 8),
                            StatusBadge(status: h.recent[k].status, label: statusLabel[h.recent[k].status] ?? h.recent[k].status),
                          ],
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            );
            children.add(ResponsiveColumns(left: left, right: right));
          }
          return ListView(padding: const EdgeInsets.all(16), physics: const AlwaysScrollableScrollPhysics(), children: children);
        },
      ),
    );
  }
}
