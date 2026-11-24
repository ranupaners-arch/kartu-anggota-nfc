import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../app/format.dart';
import '../offline/offline_store.dart';
import '../widgets/scan_result_card.dart';
import '../widgets/ui.dart';

/// Scan offline yang ditolak server saat sinkron: siapa, kegiatan apa, dan alasannya.
/// Tetap tersimpan di HP sampai petugas menandainya selesai.
class RejectedScansScreen extends StatefulWidget {
  const RejectedScansScreen({super.key, required this.store});

  final OfflineStore store;

  @override
  State<RejectedScansScreen> createState() => _RejectedScansScreenState();
}

class _RejectedScansScreenState extends State<RejectedScansScreen> {
  Future<void> _done(RejectedScan r) async {
    final ok = await showShadDialog<bool>(
      context: context,
      builder: (context) => ShadDialog(
        title: const Text('Sudah ditangani?'),
        description: Text('Catatan ${r.memberName ?? 'kartu tidak dikenal'} dihapus dari HP ini. Kehadirannya tidak berubah di server.'),
        actions: [
          ShadButton.outline(onPressed: () => Navigator.of(context).pop(false), child: const Text('Batal')),
          ShadButton(onPressed: () => Navigator.of(context).pop(true), child: const Text('Hapus catatan')),
        ],
      ),
    );
    if (ok != true) return;
    await widget.store.removeRejected(r.clientUuid);
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    final items = widget.store.rejected().reversed.toList();
    return AppPage(
      title: 'Scan ditolak',
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(items.isEmpty ? 'Tidak ada scan yang ditolak.' : 'Scan offline ini tidak tercatat sebagai hadir. Laporkan ke pengurus bila perlu dikoreksi.', style: theme.textTheme.muted),
          for (final r in items) ...[
            const SizedBox(height: 12),
            SectionCard(
              title: r.memberName ?? 'Kartu tidak dikenal',
              icon: LucideIcons.circleX,
              description: [?r.memberNo, r.eventTitle ?? 'Kegiatan #${r.eventId}', fmtDateTime(r.scannedAt.toLocal())].join(' · '),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                spacing: 12,
                children: [
                  Text('${ScanResultCard.labelFor(r.status)}: ${r.message}', style: theme.textTheme.small),
                  FlexButton(label: 'Sudah ditangani', icon: LucideIcons.check, small: true, variant: ShadButtonVariant.outline, onPressed: () => _done(r)),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}
