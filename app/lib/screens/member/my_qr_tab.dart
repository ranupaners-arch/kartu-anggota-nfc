import 'dart:async';

import 'package:material_ui/material_ui.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../widgets/ui.dart';

/// QR pribadi anggota. Server merotasi QR tiap menit; app memuat ulang sebelum kedaluwarsa.
class MyQrTab extends StatefulWidget {
  const MyQrTab({super.key, required this.api, required this.name, this.refreshEvery = const Duration(seconds: 50)});

  final ApiClient api;
  final String name;
  final Duration refreshEvery;

  @override
  State<MyQrTab> createState() => _MyQrTabState();
}

class _MyQrTabState extends State<MyQrTab> {
  String? _payload;
  String? _error;
  Timer? _refresh, _tick;
  DateTime _nextAt = DateTime.now();

  @override
  void initState() {
    super.initState();
    _load();
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _refresh?.cancel();
    _tick?.cancel();
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
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (_error != null) ...[ErrorBanner(_error!), const SizedBox(height: 12)],
        ShadCard(
          child: Column(
            spacing: 12,
            children: [
              Text(widget.name, style: theme.textTheme.large),
              Container(
                // Latar putih selalu (juga di mode gelap) agar mudah dipindai kamera.
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(color: Colors.white, borderRadius: theme.radius),
                child: _payload == null
                    ? const SizedBox(width: 240, height: 240, child: Center(child: CircularProgressIndicator()))
                    : QrImageView(
                        data: _payload!,
                        size: 240,
                        padding: EdgeInsets.zero,
                        backgroundColor: Colors.white,
                        errorCorrectionLevel: QrErrorCorrectLevel.M,
                        semanticsLabel: 'QR absensi ${widget.name}',
                      ),
              ),
              Text(_payload == null ? 'Memuat…' : 'Diperbarui otomatis dalam $left detik', style: theme.textTheme.muted),
            ],
          ),
        ),
        const SizedBox(height: 12),
        ShadAlert(
          icon: const Icon(LucideIcons.info),
          title: const Text('Cadangan saat kartu tertinggal'),
          description: const Text('Tunjukkan QR ini ke petugas. QR berganti tiap menit, jadi tangkapan layar tidak bisa dipakai. Butuh koneksi internet.'),
        ),
      ],
    );
  }
}
