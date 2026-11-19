import 'package:material_ui/material_ui.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../app/server_config.dart';
import 'login_screen.dart';
import '../widgets/ui.dart';

/// Hubungkan app ke server organisasi (model self-hosted): scan QR dari dashboard atau ketik alamat.
class ConnectScreen extends StatefulWidget {
  const ConnectScreen({super.key, this.verify = ServerAddress.verify, this.initialUrl});

  /// Bisa diganti di tes.
  final Future<ServerConfig> Function(String url) verify;

  /// Dari tautan QR penghubung: alamat langsung diisi & diperiksa.
  final String? initialUrl;

  @override
  State<ConnectScreen> createState() => _ConnectScreenState();
}

class _ConnectScreenState extends State<ConnectScreen> {
  final _address = TextEditingController();
  bool _scanning = false;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final url = widget.initialUrl;
    if (url != null) {
      _address.text = url;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _connect(url);
      });
    }
  }

  @override
  void dispose() {
    _address.dispose();
    super.dispose();
  }

  Future<void> _connect(String url) async {
    setState(() {
      _busy = true;
      _error = null;
      _scanning = false;
    });
    try {
      final server = await widget.verify(ServerAddress.normalize(url));
      await server.save();
      if (!mounted) return;
      Navigator.of(context).pushReplacement(MaterialPageRoute<void>(builder: (_) => LoginScreen(server: server)));
    } on ServerConnectException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _onQr(BarcodeCapture capture) {
    if (_busy) return;
    final raw = capture.barcodes.firstOrNull?.rawValue;
    final parsed = raw == null ? null : ServerAddress.parseQr(raw);
    if (parsed == null) {
      setState(() => _error = 'QR ini bukan QR penghubung. Buka dashboard → Pengaturan → Aplikasi.');
      return;
    }
    _connect(parsed.url);
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return Scaffold(
      backgroundColor: theme.colorScheme.muted,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: ShadCard(
                title: const Text('Hubungkan ke Organisasi'),
                description: const Text('Minta pengurus membuka dashboard → Pengaturan → Aplikasi, lalu scan QR-nya.'),
                child: Padding(
                  padding: const EdgeInsets.only(top: 16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    spacing: 12,
                    children: [
                      if (_error != null) ShadAlert.destructive(icon: const Icon(LucideIcons.circleAlert), description: Text(_error!)),
                      if (_scanning)
                        ClipRRect(
                          borderRadius: theme.radius,
                          child: AspectRatio(aspectRatio: 1, child: MobileScanner(onDetect: _onQr)),
                        )
                      else
                        FlexButton(
                          enabled: !_busy,
                          icon: LucideIcons.scanQrCode,
                          onPressed: () => setState(() {
                            _scanning = true;
                            _error = null;
                          }),
                          label: 'Scan QR dari dashboard',
                        ),
                      Row(
                        children: [
                          const Expanded(child: Divider()),
                          Padding(
                            padding: const EdgeInsets.symmetric(horizontal: 8),
                            child: Text('atau', style: theme.textTheme.muted),
                          ),
                          const Expanded(child: Divider()),
                        ],
                      ),
                      Text('Alamat server', style: theme.textTheme.small),
                      ShadInput(
                        controller: _address,
                        keyboardType: TextInputType.url,
                        placeholder: const Text('absen.organisasi.id'),
                        leading: const Icon(LucideIcons.server, size: 16),
                        onSubmitted: (v) => _busy ? null : _connect(v),
                      ),
                      FlexButton(variant: ShadButtonVariant.outline, enabled: !_busy, onPressed: () => _connect(_address.text), label: _busy ? 'Memeriksa server…' : 'Hubungkan'),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
