import 'package:flutter/services.dart';
import 'package:material_ui/material_ui.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:nfc_manager/nfc_manager.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../api/api_client.dart';
import '../app/session.dart';
import '../nfc/nfc_service.dart';
import '../widgets/scan_result_card.dart';
import '../widgets/ui.dart';

enum ScanMode { nfc, qr }

/// Layar scan petugas: kartu NFC dibaca terus selama layar terbuka; QR lewat kamera.
class ScanScreen extends StatefulWidget {
  const ScanScreen({super.key, required this.session, required this.event, this.nfc});

  final Session session;
  final EventSummary event;
  final NfcService? nfc;

  @override
  State<ScanScreen> createState() => _ScanScreenState();
}

class _ScanScreenState extends State<ScanScreen> with WidgetsBindingObserver {
  late final ApiClient _api = widget.session.client();
  late final NfcService _nfc = widget.nfc ?? NfcService();
  ScanMode _mode = ScanMode.nfc;
  ScanResult? _last;
  String? _nfcProblem;
  bool _busy = false;
  int _loop = 0; // generasi loop NFC; berubah = loop lama berhenti
  int _okCount = 0;
  String? _lastQr;
  DateTime _lastQrAt = DateTime.fromMillisecondsSinceEpoch(0);

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _startNfc();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _loop++;
    _nfc.stop();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (_mode != ScanMode.nfc) return;
    if (state == AppLifecycleState.resumed) {
      _startNfc();
    } else if (state == AppLifecycleState.paused) {
      _loop++;
      _nfc.stop();
    }
  }

  Future<void> _startNfc() async {
    final gen = ++_loop;
    final status = await _nfc.availability();
    if (!mounted || gen != _loop) return;
    if (status != NfcAvailability.enabled) {
      setState(() => _nfcProblem = status == NfcAvailability.disabled
          ? 'NFC mati. Aktifkan NFC di pengaturan HP, lalu kembali ke sini.'
          : 'HP ini tidak mendukung NFC. Gunakan mode QR.');
      return;
    }
    setState(() => _nfcProblem = null);
    // Baca kartu berulang: tiap read() menunggu tap berikutnya.
    while (mounted && gen == _loop) {
      try {
        final r = await _nfc.read();
        if (!mounted || gen != _loop) return;
        if (r.payload == null) {
          _show(const ScanResult(status: 'invalid', message: 'Bukan kartu anggota.'));
        } else {
          await _submit(r.text!, 'nfc');
        }
      } on NfcException catch (e) {
        if (!mounted || gen != _loop) return;
        _show(ScanResult(status: 'invalid', message: e.message));
      }
    }
  }

  void _switchMode(ScanMode mode) {
    if (mode == _mode) return;
    setState(() => _mode = mode);
    if (mode == ScanMode.nfc) {
      _startNfc();
    } else {
      _loop++;
      _nfc.stop();
    }
  }

  Future<void> _onQr(BarcodeCapture capture) async {
    final raw = capture.barcodes.firstOrNull?.rawValue;
    if (raw == null || _busy) return;
    // Abaikan QR yang sama dalam 3 detik (kamera mendeteksi berkali-kali).
    if (raw == _lastQr && DateTime.now().difference(_lastQrAt) < const Duration(seconds: 3)) return;
    _lastQr = raw;
    _lastQrAt = DateTime.now();
    await _submit(raw, 'qr');
  }

  Future<void> _submit(String payload, String source) async {
    setState(() => _busy = true);
    try {
      _show(await _api.scan(widget.event.id, payload, source: source));
    } on ApiException catch (e) {
      _show(ScanResult(status: 'error', message: e.status == 401 ? 'Sesi berakhir. Keluar lalu masuk lagi.' : e.message));
    } catch (_) {
      _show(const ScanResult(status: 'error', message: 'Server tidak terjangkau. Periksa koneksi.'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _show(ScanResult r) {
    if (!mounted) return;
    if (r.ok) {
      _okCount++;
      HapticFeedback.heavyImpact();
    } else {
      HapticFeedback.vibrate();
    }
    setState(() => _last = r);
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    Widget modeButton(ScanMode m, IconData icon, String label) => Expanded(
          child: _mode == m
              ? ShadButton(leading: Icon(icon, size: 16), onPressed: () {}, child: Text(label))
              : ShadButton.outline(leading: Icon(icon, size: 16), onPressed: () => _switchMode(m), child: Text(label)),
        );

    return AppPage(
      title: widget.event.title,
      leading: const BackButton(),
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Row(spacing: 8, children: [modeButton(ScanMode.nfc, LucideIcons.nfc, 'Kartu NFC'), modeButton(ScanMode.qr, LucideIcons.qrCode, 'QR')]),
          const SizedBox(height: 16),
          if (_mode == ScanMode.nfc)
            _nfcProblem != null
                ? ShadAlert.destructive(icon: const Icon(LucideIcons.triangleAlert), title: const Text('NFC tidak siap'), description: Text(_nfcProblem!))
                : ShadCard(
                    child: Padding(
                      padding: const EdgeInsets.symmetric(vertical: 24),
                      child: Column(spacing: 8, children: [
                        Icon(LucideIcons.smartphoneNfc, size: 48, color: theme.colorScheme.mutedForeground),
                        Text(_busy ? 'Memproses…' : 'Tempelkan kartu ke belakang HP', style: theme.textTheme.large),
                        Text('Siap membaca kartu berikutnya', style: theme.textTheme.muted),
                      ]),
                    ),
                  )
          else
            ClipRRect(
              borderRadius: theme.radius,
              child: AspectRatio(aspectRatio: 1, child: MobileScanner(onDetect: _onQr)),
            ),
          const SizedBox(height: 16),
          if (_last != null) ScanResultCard(result: _last!),
          const SizedBox(height: 12),
          Center(child: Text('$_okCount berhasil tercatat di sesi ini', style: theme.textTheme.muted)),
        ],
      ),
    );
  }
}
