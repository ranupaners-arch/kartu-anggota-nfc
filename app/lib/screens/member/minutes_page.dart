import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/format.dart';
import '../../app/pdf.dart';
import '../../widgets/ui.dart';

/// "120 KB" / "1,4 MB".
String fmtSize(int n) => n < 1048576 ? '${n < 1024 ? 1 : (n / 1024).round()} KB' : '${(n / 1048576).toStringAsFixed(1).replaceAll('.', ',')} MB';

/// Notulen terbit & dokumen kegiatan yang diikuti anggota.
class MinutesPage extends StatefulWidget {
  const MinutesPage({super.key, required this.api, this.openFile});

  final ApiClient api;

  /// Untuk tes.
  final Future<String?> Function(List<int> bytes, String fileName)? openFile;

  @override
  State<MinutesPage> createState() => _MinutesPageState();
}

class _MinutesPageState extends State<MinutesPage> {
  List<MemberMinutes>? _items;
  String? _error;
  int? _downloading;
  final _open = <int>{};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    List<MemberMinutes>? items;
    String? error;
    try {
      items = await widget.api.minutes();
    } on ApiException catch (e) {
      error = e.status == 404 ? 'Server organisasi Anda belum mendukung notulen.' : e.message;
    } catch (_) {
      error = 'Server tidak terjangkau. Tarik untuk mencoba lagi.';
    }
    if (!mounted) return;
    setState(() {
      _items = items ?? _items;
      _error = error;
    });
  }

  Future<void> _download(int id, String fileName) async {
    setState(() {
      _downloading = id;
      _error = null;
    });
    String? error;
    try {
      final bytes = await widget.api.document(id);
      error = await (widget.openFile ?? (b, n) => saveAndOpenFile(b, fileName: n))(bytes, fileName);
    } on ApiException catch (e) {
      error = e.status == 404 ? 'Dokumen ini sudah tidak tersedia.' : e.message;
    } catch (_) {
      error = 'Gagal mengunduh. Periksa koneksi.';
    }
    if (!mounted) return;
    setState(() {
      _downloading = null;
      _error = error;
    });
  }

  @override
  Widget build(BuildContext context) {
    final items = _items;
    final theme = ShadTheme.of(context);
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          if (_error != null) ...[ErrorBanner(_error!), const SizedBox(height: 12)],
          if (items == null && _error == null)
            const Padding(
              padding: EdgeInsets.all(32),
              child: Center(child: CircularProgressIndicator()),
            ),
          if (items != null && items.isEmpty) Text('Belum ada notulen atau dokumen dari kegiatan yang Anda ikuti.', style: theme.textTheme.muted),
          for (final m in items ?? const <MemberMinutes>[])
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: SectionCard(
                title: m.title,
                icon: LucideIcons.notebookText,
                description: fmtDate(m.date),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  spacing: 12,
                  children: [
                    if (m.content != null) ...[
                      if (_open.contains(m.eventId)) SelectableText(m.content!, style: theme.textTheme.p),
                      FlexButton(
                        label: _open.contains(m.eventId) ? 'Tutup notulen' : 'Baca notulen',
                        icon: _open.contains(m.eventId) ? LucideIcons.chevronUp : LucideIcons.bookOpen,
                        variant: ShadButtonVariant.outline,
                        onPressed: () => setState(() => _open.contains(m.eventId) ? _open.remove(m.eventId) : _open.add(m.eventId)),
                      ),
                    ],
                    for (final d in m.documents)
                      FlexButton(
                        label: _downloading == d.id ? 'Mengunduh…' : '${d.title} (${fmtSize(d.size)})',
                        icon: LucideIcons.fileDown,
                        variant: ShadButtonVariant.outline,
                        enabled: _downloading == null,
                        onPressed: () => _download(d.id, d.fileName),
                      ),
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }
}
