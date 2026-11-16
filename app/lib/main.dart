import 'package:material_ui/material_ui.dart';
import 'package:nfc_manager/nfc_manager.dart';

import 'debug/qr_scan_page.dart';
import 'nfc/nfc_service.dart';

void main() => runApp(const KartuAnggotaApp());

class KartuAnggotaApp extends StatelessWidget {
  const KartuAnggotaApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Kartu Anggota',
      theme: ThemeData(colorSchemeSeed: Colors.indigo, useMaterial3: true),
      home: const NfcDebugPage(),
    );
  }
}

/// Layar uji NFC sementara (prototipe M0), bukan UI final.
class NfcDebugPage extends StatefulWidget {
  const NfcDebugPage({super.key, this.service});

  final NfcService? service;

  @override
  State<NfcDebugPage> createState() => _NfcDebugPageState();
}

class _NfcDebugPageState extends State<NfcDebugPage> with WidgetsBindingObserver {
  late final NfcService _nfc = widget.service ?? NfcService();
  final _payload = TextEditingController();
  final _pwd = TextEditingController();
  final _pack = TextEditingController();
  final _log = <String>[];
  bool _protect = true;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _nfc.onIdleTag = (i) => _add('• Kartu terdeteksi (${i.techs.join(', ')}). Pilih aksi dulu, lalu tempel lagi.');
    _startNfc();
  }

  /// Pegang NFC selama layar terbuka agar kartu tidak dilempar ke aplikasi lain.
  Future<void> _startNfc() async {
    try {
      final status = await _nfc.availability();
      if (status != NfcAvailability.enabled) {
        _add('✗ NFC ${status.name}. Aktifkan NFC di pengaturan HP, lalu buka ulang app.');
        return;
      }
      await _nfc.start();
    } catch (e) {
      _add('✗ Gagal mengaktifkan NFC: $e');
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _startNfc();
    } else if (state == AppLifecycleState.paused) {
      _nfc.stop();
    }
  }

  void _add(String line) {
    if (mounted) setState(() => _log.insert(0, line));
  }

  Future<void> _run(String label, Future<String> Function() op) async {
    setState(() => _busy = true);
    _add('▶ $label — tempelkan kartu ke belakang HP…');
    try {
      final status = await _nfc.availability();
      if (status != NfcAvailability.enabled) {
        _add('✗ NFC ${status.name}. Aktifkan NFC di pengaturan HP.');
        return;
      }
      _add('✓ ${await op()}');
    } catch (e) {
      _add('✗ $e');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _nfc.stop();
    _payload.dispose();
    _pwd.dispose();
    _pack.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Uji NFC'),
        actions: [
          TextButton.icon(
            onPressed: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const QrScanPage())),
            icon: const Icon(Icons.qr_code_scanner),
            label: const Text('Uji QR'),
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          FilledButton.tonal(
            onPressed: _busy
                ? null
                : () => _run('Deteksi', () async {
                      final i = await _nfc.detect();
                      return 'Terdeteksi: ${i.techs.join(', ')} · UID ${i.uidMasked}';
                    }),
            child: const Text('Deteksi tag (aman untuk e-KTP)'),
          ),
          const SizedBox(height: 8),
          FilledButton.tonal(
            onPressed: _busy
                ? null
                : () => _run('Baca kartu', () async {
                      final r = await _nfc.read();
                      final p = r.payload;
                      return p == null
                          ? 'Isi: ${r.text ?? '(kosong)'} — bukan format kartu anggota'
                          : 'Kartu valid (format). key=${p.keyId} id=${p.publicId}';
                    }),
            child: const Text('Baca kartu anggota (NTAG)'),
          ),
          const Divider(height: 32),
          const Text('Tulis kartu NTAG215 — isi dari respons POST /api/cards/init'),
          TextField(controller: _payload, decoration: const InputDecoration(labelText: 'payload')),
          Row(children: [
            Expanded(child: TextField(controller: _pwd, decoration: const InputDecoration(labelText: 'ntag.pwd (8 hex)'))),
            const SizedBox(width: 8),
            Expanded(child: TextField(controller: _pack, decoration: const InputDecoration(labelText: 'ntag.pack (4 hex)'))),
          ]),
          CheckboxListTile(
            value: _protect,
            onChanged: (v) => setState(() => _protect = v ?? true),
            title: const Text('Proteksi tulis dengan password'),
            subtitle: const Text('Baca tetap bebas. Tidak permanen (bisa ditulis ulang dengan password).'),
            contentPadding: EdgeInsets.zero,
          ),
          FilledButton(
            onPressed: _busy
                ? null
                : () => _run('Tulis kartu', () async {
                      final w = await _nfc.write(
                        payload: _payload.text,
                        pwdHex: _pwd.text,
                        packHex: _pack.text,
                        protect: _protect,
                      );
                      return 'Tertulis ke ${w.model.name}, proteksi=${w.protectedNow}. hw_uid untuk assign: ${w.uidHex}';
                    }),
            child: const Text('Tulis kartu'),
          ),
          const Divider(height: 32),
          for (final line in _log) Padding(padding: const EdgeInsets.only(bottom: 6), child: SelectableText(line)),
        ],
      ),
    );
  }
}
