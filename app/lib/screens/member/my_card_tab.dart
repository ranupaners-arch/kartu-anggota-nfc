import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../widgets/member_card.dart';
import '../../widgets/ui.dart';
import 'my_qr_tab.dart';
import 'self_card_screen.dart';

/// Tab "Kartu": kartu anggota di halaman utama. Kosong → ajakan isi e-profil; menunggu → info
/// pengesahan; aktif → kartu lengkap + QR cadangan (kartu tertinggal).
class MyCardTab extends StatefulWidget {
  const MyCardTab({super.key, required this.api, required this.name, this.onRegister, this.selfCardScreen});

  final ApiClient api;
  final String name;

  /// Dipanggil saat kartu kosong diketuk (isi e-profil). Mengembalikan true bila e-profil terkirim.
  final Future<bool?> Function(BuildContext context)? onRegister;

  /// Untuk tes: layar daftar kartu sendiri (bawaan: SelfCardScreen dengan NFC asli).
  final Widget Function(ApiClient api)? selfCardScreen;

  @override
  State<MyCardTab> createState() => _MyCardTabState();
}

class _MyCardTabState extends State<MyCardTab> {
  late Future<MemberCardInfo> _card = widget.api.myCard();

  Future<void> _refresh() async {
    setState(() {
      _card = widget.api.myCard();
    });
    await _card.catchError((_) => MemberCardInfo(state: CardState.none));
  }

  Future<void> _registerOwnCard() async {
    final ok = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => widget.selfCardScreen?.call(widget.api) ?? SelfCardScreen(api: widget.api)),
    );
    if (ok == true) _refresh();
  }

  Future<void> _requestReplacement() async {
    final sent = await showShadDialog<bool>(context: context, builder: (_) => _ReplacementDialog(api: widget.api));
    if (sent == true) _refresh();
  }

  void _showQr() => Navigator.of(context).push(
    MaterialPageRoute<void>(
      builder: (_) => Scaffold(
        body: AppPage(title: 'QR Saya', child: MyQrTab(api: widget.api, name: widget.name)),
      ),
    ),
  );

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return RefreshIndicator(
      onRefresh: _refresh,
      child: FutureBuilder<MemberCardInfo>(
        future: _card,
        builder: (context, snap) {
          final children = <Widget>[];
          if (snap.connectionState != ConnectionState.done) {
            children.add(const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator())));
          } else if (snap.hasError) {
            children.add(ErrorBanner(snap.error is ApiException ? (snap.error as ApiException).message : 'Tidak bisa memuat kartu. Tarik untuk mencoba lagi.'));
          } else {
            final info = snap.data!;
            children.add(
              MemberCardView(
                info: info,
                token: widget.api.token,
                onRegister: widget.onRegister == null
                    ? null
                    : () async {
                        final sent = await widget.onRegister!(context);
                        if (sent == true) _refresh();
                      },
              ),
            );
            children.add(const SizedBox(height: 16));
            switch (info.state) {
              case CardState.none:
                children.add(
                  ShadAlert(
                    icon: const Icon(LucideIcons.info),
                    title: const Text('Belum terdaftar sebagai anggota'),
                    description: Text(widget.onRegister == null ? 'Hubungi pengurus untuk mendaftarkan keanggotaan Anda.' : 'Ketuk kartu di atas untuk mengisi e-profil.'),
                  ),
                );
              case CardState.pending:
                children.add(
                  const ShadAlert(
                    icon: Icon(LucideIcons.hourglass),
                    title: Text('Menunggu pengesahan pusat'),
                    description: Text('E-profil Anda sedang diperiksa pengurus. Kartu aktif otomatis setelah disahkan — tarik layar untuk memperbarui.'),
                  ),
                );
              case CardState.inactive:
                children.add(
                  ShadAlert(
                    icon: const Icon(LucideIcons.info),
                    title: Text(info.status == 'alumni' ? 'Status: alumni' : 'Keanggotaan tidak aktif'),
                    description: const Text('Kartu tidak bisa dipakai untuk absensi periode ini. Hubungi pengurus bila ini keliru.'),
                  ),
                );
              case CardState.active:
                if (info.canWriteCard) {
                  children.add(FlexButton(label: 'Daftarkan kartu NFC saya', icon: LucideIcons.nfc, onPressed: _registerOwnCard));
                  children.add(const SizedBox(height: 8));
                }
                children.add(FlexButton(label: 'Tampilkan QR (kartu tertinggal)', icon: LucideIcons.qrCode, variant: ShadButtonVariant.outline, onPressed: _showQr));
                children.add(const SizedBox(height: 8));
                if (info.pendingRequestReason != null) {
                  children.add(
                    ShadAlert(
                      icon: const Icon(LucideIcons.hourglass),
                      title: const Text('Permintaan kartu pengganti menunggu persetujuan'),
                      description: Text('Alasan: ${info.pendingRequestReason}. Setelah disetujui pengurus, Anda bisa mendaftarkan kartu baru di sini.'),
                    ),
                  );
                  children.add(const SizedBox(height: 8));
                } else if (info.canRequestCard) {
                  children.add(FlexButton(label: 'Kartu hilang/rusak? Ajukan pengganti', icon: LucideIcons.creditCard, variant: ShadButtonVariant.ghost, small: true, onPressed: _requestReplacement));
                  children.add(const SizedBox(height: 8));
                }
                children.add(
                  Text(
                    info.cardLast4 != null ? 'Kartu NFC aktif (…${info.cardLast4}). Tempelkan ke HP petugas saat absensi.' : 'Kartu NFC fisik belum diterbitkan. Sementara gunakan QR.',
                    style: theme.textTheme.muted,
                    textAlign: TextAlign.center,
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

/// Ajukan kartu pengganti: alasan (hilang/rusak) + catatan. "Hilang" langsung memblokir kartu lama.
class _ReplacementDialog extends StatefulWidget {
  const _ReplacementDialog({required this.api});

  final ApiClient api;

  @override
  State<_ReplacementDialog> createState() => _ReplacementDialogState();
}

class _ReplacementDialogState extends State<_ReplacementDialog> {
  String _reason = 'hilang';
  final _note = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.api.requestCardReplacement(_reason, _note.text.trim());
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (_) {
      setState(() => _error = 'Server tidak terjangkau.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => ShadDialog(
    title: const Text('Ajukan kartu pengganti'),
    description: const Text('Pengurus akan meninjau. Bila disetujui, Anda bisa mendaftarkan kartu NFC baru sekali lagi.'),
    actions: [
      ShadButton.outline(onPressed: () => Navigator.of(context).pop(false), child: const Text('Batal')),
      ShadButton(enabled: !_busy, onPressed: _busy ? null : _send, child: Text(_busy ? 'Mengirim…' : 'Ajukan')),
    ],
    child: Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        spacing: 10,
        children: [
          if (_error != null) ShadAlert.destructive(icon: const Icon(LucideIcons.circleAlert), description: Text(_error!)),
          ShadRadioGroup<String>(
            initialValue: _reason,
            onChanged: (v) => setState(() => _reason = v ?? _reason),
            items: const [
              ShadRadio(value: 'hilang', label: Text('Hilang — kartu lama langsung diblokir')),
              ShadRadio(value: 'rusak', label: Text('Rusak / tidak terbaca')),
            ],
          ),
          ShadInput(controller: _note, placeholder: const Text('Catatan (opsional)'), maxLines: 2),
        ],
      ),
    ),
  );
}
