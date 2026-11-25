import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/format.dart';
import '../../app/pdf.dart';
import '../../widgets/ui.dart';

/// Sertifikat kegiatan milik sendiri: daftar & unduh PDF.
class CertificatesPage extends StatefulWidget {
  const CertificatesPage({super.key, required this.api, this.openPdf});

  final ApiClient api;

  /// Untuk tes.
  final Future<String?> Function(List<int> bytes)? openPdf;

  @override
  State<CertificatesPage> createState() => _CertificatesPageState();
}

class _CertificatesPageState extends State<CertificatesPage> {
  List<MemberCertificate>? _items;
  String? _error;
  int? _downloading;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    List<MemberCertificate>? items;
    String? error;
    try {
      items = await widget.api.certificates();
    } on ApiException catch (e) {
      error = e.status == 404 ? 'Server organisasi Anda belum mendukung sertifikat.' : e.message;
    } catch (_) {
      error = 'Server tidak terjangkau. Tarik untuk mencoba lagi.';
    }
    if (!mounted) return;
    setState(() {
      _items = items ?? _items;
      _error = error;
    });
  }

  Future<void> _download(MemberCertificate c) async {
    setState(() {
      _downloading = c.id;
      _error = null;
    });
    String? error;
    try {
      final bytes = await widget.api.certificatePdf(c.id);
      error = await (widget.openPdf ?? (b) => saveAndOpenPdf(b, fileName: 'sertifikat-${c.id}.pdf'))(bytes);
    } on ApiException catch (e) {
      error = e.status == 410 ? 'Sertifikat ini sudah dicabut pengurus.' : e.message;
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
          if (items != null && items.isEmpty) Text('Belum ada sertifikat.', style: ShadTheme.of(context).textTheme.muted),
          for (final c in items ?? const <MemberCertificate>[])
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: SectionCard(
                title: c.event,
                icon: LucideIcons.fileBadge,
                description: '${fmtDate(c.date)} · ${c.number}',
                child: FlexButton(
                  label: _downloading == c.id ? 'Mengunduh…' : 'Unduh PDF',
                  icon: LucideIcons.fileDown,
                  variant: ShadButtonVariant.outline,
                  enabled: _downloading == null,
                  onPressed: () => _download(c),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
