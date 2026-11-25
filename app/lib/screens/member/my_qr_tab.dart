import 'dart:async';

import 'package:material_ui/material_ui.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/device_settings.dart';
import '../../widgets/ui.dart';

/// QR pribadi anggota. Server merotasi QR tiap menit; app memuat ulang sebelum kedaluwarsa.
class MyQrTab extends StatefulWidget {
  const MyQrTab({super.key, required this.api, required this.name, this.refreshEvery = const Duration(seconds: 50), this.screenMode = setQrScreenMode});

  final ApiClient api;
  final String name;
  final Duration refreshEvery;

  /// Untuk tes: atur layar tetap menyala & kecerahan maksimal selama QR tampil.
  final Future<void> Function(bool on) screenMode;

  @override
  State<MyQrTab> createState() => _MyQrTabState();
}

class _MyQrTabState extends State<MyQrTab> {
  String? _payload;
  String? _error;
  Timer? _refresh, _tick;
  DateTime _nextAt = DateTime.now();

  /// Identitas di bawah QR (nama, nomor, divisi) — sama dengan portal web.
  MemberCardInfo? _info;

  @override
  void initState() {
    super.initState();
    widget.screenMode(true);
    _load();
    widget.api.myCard().then((c) {
      if (mounted) setState(() => _info = c);
    }, onError: (_) {});
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _refresh?.cancel();
    _tick?.cancel();
    widget.screenMode(false); // kembalikan kecerahan & izinkan layar mati lagi
    super.dispose();
  }

  Future<void> _load() async {
    _refresh?.cancel();
    try {
      final qr = await widget.api.myQr();
      if (!mounted) return;
      setState(() {
        _payload = qr.payload;
        _error = null;
        _nextAt = DateTime.now().add(widget.refreshEvery);
      });
    } catch (e) {
      if (!mounted) return;
      setState(() => _error = e is ApiException ? e.message : 'Tidak bisa memuat QR. Periksa koneksi.');
    }
    _refresh = Timer(_error == null ? widget.refreshEvery : const Duration(seconds: 10), _load);
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    final left = _nextAt.difference(DateTime.now()).inSeconds.clamp(0, 999);
    final total = widget.refreshEvery.inSeconds.clamp(1, 999);
    final name = _info?.name ?? widget.name;
    final sub = [if (_info?.memberNo != null) _info!.memberNo!, if (_info?.division != null) _info!.division!].join(' · ');
    return LayoutBuilder(
      builder: (context, box) {
        // QR sebesar mungkin tapi tetap muat: lebar layar dikurangi tepi, maksimal 280.
        final size = (box.maxWidth - 32 - 32 - 32).clamp(160.0, 280.0);
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (_error != null) ...[ErrorBanner(_error!), const SizedBox(height: 12)],
            SectionCard(
              child: Column(
                children: [
                  const SizedBox(height: 8),
                  // Latar putih selalu (juga di mode gelap) agar mudah dipindai kamera.
                  AnimatedOpacity(
                    duration: const Duration(milliseconds: 200),
                    opacity: _error != null ? 0.3 : 1,
                    child: Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: theme.colorScheme.border),
                      ),
                      child: _payload == null
                          ? SizedBox(
                              width: size,
                              height: size,
                              child: const Center(child: CircularProgressIndicator()),
                            )
                          : QrImageView(
                              data: _payload!,
                              size: size,
                              padding: EdgeInsets.zero,
                              backgroundColor: Colors.white,
                              errorCorrectionLevel: QrErrorCorrectLevel.M,
                              semanticsLabel: 'QR absensi $name',
                            ),
                    ),
                  ),
                  const SizedBox(height: 16),
                  Text(name, style: theme.textTheme.large, textAlign: TextAlign.center, maxLines: 2, overflow: TextOverflow.ellipsis),
                  if (sub.isNotEmpty) Text(sub, style: theme.textTheme.muted, textAlign: TextAlign.center),
                  const SizedBox(height: 12),
                  Text('Tunjukkan ke petugas untuk dipindai.', style: theme.textTheme.small, textAlign: TextAlign.center),
                  const SizedBox(height: 12),
                  // Hitung mundur QR berikutnya.
                  SizedBox(
                    width: size,
                    child: Column(
                      children: [
                        ClipRRect(
                          borderRadius: BorderRadius.circular(4),
                          child: LinearProgressIndicator(value: _payload == null ? null : left / total, minHeight: 4, backgroundColor: theme.colorScheme.muted, color: theme.colorScheme.primary),
                        ),
                        const SizedBox(height: 6),
                        Text(
                          _payload == null ? 'Memuat…' : 'QR baru dalam $left detik',
                          style: theme.textTheme.muted.copyWith(fontSize: 12, fontFeatures: const [FontFeature.tabularFigures()]),
                          textAlign: TextAlign.center,
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 4),
                ],
              ),
            ),
            const SizedBox(height: 16),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(LucideIcons.sun, size: 16, color: theme.colorScheme.mutedForeground),
                const SizedBox(width: 8),
                Expanded(child: Text('Layar dibuat paling terang & tetap menyala selama QR tampil.', style: theme.textTheme.muted.copyWith(fontSize: 12))),
              ],
            ),
            const SizedBox(height: 8),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(LucideIcons.shieldAlert, size: 16, color: theme.colorScheme.mutedForeground),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    'QR berganti tiap menit dan butuh internet. Absen dengan QR bisa dikenai denda.',
                    style: theme.textTheme.muted.copyWith(fontSize: 12),
                  ),
                ),
              ],
            ),
          ],
        );
      },
    );
  }
}
