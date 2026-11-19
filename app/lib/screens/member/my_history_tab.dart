import 'dart:io';

import 'package:material_ui/material_ui.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/format.dart';
import '../../widgets/ui.dart';

/// Simpan PDF ke folder sementara lalu buka dengan penampil PDF HP. Kembalikan pesan galat atau null.
Future<String?> _saveAndOpenPdf(List<int> bytes) async {
  final f = File('${(await getTemporaryDirectory()).path}/rapor-kehadiran.pdf');
  await f.writeAsBytes(bytes, flush: true);
  final r = await OpenFilex.open(f.path, type: 'application/pdf');
  return r.type == ResultType.done ? null : 'Tidak ada aplikasi untuk membuka PDF di HP ini.';
}

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
    setState(() => _history = widget.api.myHistory());
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
            children.add(
              ShadCard(
                title: Text(h.rate == null ? '–' : '${h.rate!.toStringAsFixed(h.rate! % 1 == 0 ? 0 : 1)}%', style: theme.textTheme.h2),
                description: Text('Kehadiran${h.term != null ? ' periode ${h.term}' : ''} · ${h.total} kegiatan'),
                child: Padding(
                  padding: const EdgeInsets.only(top: 12),
                  child: Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      for (final s in ['hadir', 'telat', 'izin', 'sakit', 'alpa']) ShadBadge.secondary(child: Text('${statusLabel[s]} ${h.byStatus[s] ?? 0}')),
                    ],
                  ),
                ),
              ),
            );
            children.add(const SizedBox(height: 12));
            children.add(
              FlexButton(
                variant: ShadButtonVariant.outline,
                icon: LucideIcons.fileDown,
                enabled: !_downloading,
                onPressed: _downloadReport,
                label: _downloading ? 'Mengunduh rapor…' : 'Unduh rapor PDF',
              ),
            );
            if (_downloadError != null) children.addAll([const SizedBox(height: 8), ErrorBanner(_downloadError!)]);
            children.add(const SizedBox(height: 12));
            if (h.recent.isEmpty) {
              children.add(Text('Belum ada riwayat kehadiran.', style: theme.textTheme.muted));
            }
            for (final r in h.recent) {
              children.add(
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: ShadCard(
                    padding: const EdgeInsets.all(12),
                    title: Text(r.event),
                    description: Text(r.date == null ? '' : fmtDate(r.date!)),
                    trailing: ShadBadge.outline(child: Text(statusLabel[r.status] ?? r.status)),
                  ),
                ),
              );
            }
          }
          return ListView(padding: const EdgeInsets.all(16), physics: const AlwaysScrollableScrollPhysics(), children: children);
        },
      ),
    );
  }
}
