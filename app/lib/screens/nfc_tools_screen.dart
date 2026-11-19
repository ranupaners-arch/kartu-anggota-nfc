import 'package:material_ui/material_ui.dart';
import 'package:nfc_manager/nfc_manager.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../nfc/nfc_service.dart';
import '../widgets/ui.dart';

/// Alat uji NFC (untuk pengurus/pengembang): deteksi, baca, dan tulis kartu.
class NfcToolsScreen extends StatefulWidget {
  const NfcToolsScreen({super.key, this.service});

  final NfcService? service;

  @override
  State<NfcToolsScreen> createState() => _NfcToolsScreenState();
}

class _NfcToolsScreenState extends State<NfcToolsScreen> with WidgetsBindingObserver {
  late final NfcService _nfc = widget.service ?? NfcService();
  final _payload = TextEditingController();
  final _pwd = TextEditingController();
  final _pack = TextEditingController();
  final _log = <({bool ok, String text})>[];
  bool _protect = true;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _nfc.onIdleTag = (i) => _add(true, 'Kartu terdeteksi (${i.techs.join(', ')}). Pilih aksi dulu, lalu tempel lagi.');
    _startNfc();
  }

  Future<void> _startNfc() async {
    try {
      final status = await _nfc.availability();
      if (status != NfcAvailability.enabled) return _add(false, 'NFC ${status.name}. Aktifkan NFC di pengaturan HP.');
      await _nfc.start();
    } catch (e) {
      _add(false, 'Gagal mengaktifkan NFC: ${e is NfcException ? e.message : e}');
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

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _nfc.stop();
    _payload.dispose();
    _pwd.dispose();
    _pack.dispose();
    super.dispose();
  }

  void _add(bool ok, String text) {
    if (mounted) setState(() => _log.insert(0, (ok: ok, text: text)));
  }

  Future<void> _run(String label, Future<String> Function() op) async {
    setState(() => _busy = true);
    _add(true, '$label — tempelkan kartu ke belakang HP…');
    try {
      _add(true, await op());
    } catch (e) {
      _add(false, e is NfcException ? e.message : 'Gagal: $e');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return AppPage(
      title: 'Alat Uji NFC',
      leading: const BackButton(),
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          ShadCard(
            title: const Text('Baca'),
            description: const Text('Deteksi aman untuk kartu apa pun (termasuk e-KTP): hanya jenis chip & UID tersamar.'),
            child: Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                spacing: 8,
                children: [
                  ShadButton.outline(
                    enabled: !_busy,
                    leading: const Icon(LucideIcons.scanLine, size: 16),
                    child: const Text('Deteksi tag'),
                    onPressed: () => _run('Deteksi', () async {
                      final i = await _nfc.detect();
                      return 'Terdeteksi: ${i.techs.join(', ')} · UID ${i.uidMasked}';
                    }),
                  ),
                  ShadButton.outline(
                    enabled: !_busy,
                    leading: const Icon(LucideIcons.creditCard, size: 16),
                    child: const Text('Baca kartu anggota (NTAG)'),
                    onPressed: () => _run('Baca kartu', () async {
                      final r = await _nfc.read();
                      final p = r.payload;
                      return p == null ? 'Isi: ${r.text ?? '(kosong)'} — bukan format kartu anggota' : 'Kartu valid (format). key=${p.keyId} id=${p.publicId}';
                    }),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 12),
          ShadCard(
            title: const Text('Tulis kartu NTAG215'),
            description: const Text('Isi dari respons POST /api/cards/init.'),
            child: Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                spacing: 8,
                children: [
                  ShadInput(controller: _payload, placeholder: const Text('payload')),
                  Row(
                    spacing: 8,
                    children: [
                      Expanded(
                        child: ShadInput(controller: _pwd, placeholder: const Text('ntag.pwd (8 hex)')),
                      ),
                      Expanded(
                        child: ShadInput(controller: _pack, placeholder: const Text('ntag.pack (4 hex)')),
                      ),
                    ],
                  ),
                  ShadCheckbox(
                    value: _protect,
                    onChanged: (v) => setState(() => _protect = v),
                    label: const Text('Proteksi tulis dengan password'),
                    sublabel: const Text('Baca tetap bebas. Tidak permanen.'),
                  ),
                  ShadButton(
                    enabled: !_busy,
                    child: const Text('Tulis kartu'),
                    onPressed: () => _run('Tulis kartu', () async {
                      final w = await _nfc.write(payload: _payload.text, pwdHex: _pwd.text, packHex: _pack.text, protect: _protect);
                      return 'Tertulis ke ${w.model.name}, proteksi=${w.protectedNow}. hw_uid: ${w.uidHex}';
                    }),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          if (_log.isNotEmpty) Text('Log (terbaru di atas)', style: theme.textTheme.small),
          for (final l in _log)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                spacing: 8,
                children: [
                  Icon(l.ok ? LucideIcons.circleCheck : LucideIcons.circleAlert, size: 16, color: l.ok ? theme.colorScheme.mutedForeground : theme.colorScheme.destructive),
                  Expanded(child: SelectableText(l.text, style: theme.textTheme.small)),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
