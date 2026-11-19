import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/format.dart';
import '../../widgets/ui.dart';

/// Ringkasan & riwayat kehadiran anggota periode berjalan.
class MyHistoryTab extends StatefulWidget {
  const MyHistoryTab({super.key, required this.api});

  final ApiClient api;

  @override
  State<MyHistoryTab> createState() => _MyHistoryTabState();
}

class _MyHistoryTabState extends State<MyHistoryTab> {
  late Future<MemberHistory> _history = widget.api.myHistory();

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
