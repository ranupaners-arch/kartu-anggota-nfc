import 'package:flutter/services.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../cards/card_issuer.dart';
import '../../nfc/nfc_service.dart';
import '../../widgets/ui.dart';

/// Anggota mendaftarkan kartu NFC kosong ke akunnya sendiri (hanya 1×; berikutnya lewat persetujuan
/// pengurus). Kartu ditulis & dikunci seperti penerbitan oleh pengurus. Mengembalikan true bila berhasil.
class SelfCardScreen extends StatefulWidget {
  const SelfCardScreen({super.key, required this.api, this.writer, this.nfc});

  final ApiClient api;

  /// Untuk tes.
  final CardWriter? writer;
  final NfcService? nfc;

  @override
  State<SelfCardScreen> createState() => _SelfCardScreenState();
}

class _SelfCardScreenState extends State<SelfCardScreen> {
  late final NfcService _nfc = widget.nfc ?? NfcService();
  late final SelfCardIssuer _issuer = SelfCardIssuer(widget.api, widget.writer ?? (init) => _nfc.write(payload: init.payload, pwdHex: init.pwd, packHex: init.pack, protect: true));
  IssueStep? _step;
  String? _error;

  @override
  void dispose() {
    _nfc.stop();
    super.dispose();
  }

  Future<void> _run(Future<void> Function() op) async {
    setState(() => _error = null);
    try {
      await op();
      HapticFeedback.heavyImpact();
    } on NfcException catch (e) {
      setState(() {
        _step = null;
        _error = 'Kartu belum tertulis: ${e.message} Coba lagi dengan kartu yang sama.';
      });
    } on ApiException catch (e) {
      setState(() {
        _error = _issuer.needsRelink ? 'Kartu sudah tertulis tetapi belum aktif: ${e.message}' : e.message;
        if (!_issuer.needsRelink) _step = null;
      });
    } catch (_) {
      setState(() {
        _error = _issuer.needsRelink ? 'Kartu sudah tertulis tetapi belum aktif (server tidak terjangkau).' : 'Server tidak terjangkau.';
        if (!_issuer.needsRelink) _step = null;
      });
    }
  }

  void _setStep(IssueStep s) {
    if (mounted) setState(() => _step = s);
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    final step = _step;
    final (icon, title, desc) = switch (step) {
      null => (LucideIcons.nfc, 'Siapkan kartu NFC kosong', 'Kartu NTAG215 kosong (mis. kartu yang Anda cetak sendiri). Pendaftaran kartu sendiri hanya bisa 1 kali; berikutnya lewat persetujuan pengurus.'),
      IssueStep.preparing => (LucideIcons.loader, 'Menyiapkan kartu…', 'Meminta data kartu ke server.'),
      IssueStep.waitingCard => (LucideIcons.smartphoneNfc, 'Tempelkan kartu kosong', 'Tahan kartu di belakang HP sampai selesai.'),
      IssueStep.linking => (LucideIcons.link, 'Mengaktifkan kartu…', 'Kartu sudah tertulis dan terkunci.'),
      IssueStep.done => (LucideIcons.circleCheck, 'Kartu aktif', 'Kartu siap dipakai absen. Tempelkan ke HP petugas saat kegiatan.'),
    };
    return Scaffold(
      body: AppPage(
        title: 'Daftarkan kartu',
        leading: const BackButton(),
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (_error != null) ...[ShadAlert.destructive(icon: const Icon(LucideIcons.circleAlert), description: Text(_error!)), const SizedBox(height: 12)],
            ShadCard(
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 16),
                child: Column(
                  spacing: 10,
                  children: [
                    Icon(icon, size: 44, color: step == IssueStep.done ? const Color(0xFF047857) : theme.colorScheme.mutedForeground),
                    Text(title, style: theme.textTheme.large, textAlign: TextAlign.center),
                    Text(desc, style: theme.textTheme.muted, textAlign: TextAlign.center),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),
            if (step == null) FlexButton(label: 'Mulai daftarkan kartu', icon: LucideIcons.nfc, onPressed: () => _run(() => _issuer.issue(_setStep))),
            if (_issuer.needsRelink && _error != null) FlexButton(label: 'Aktifkan ulang', onPressed: () => _run(() => _issuer.relink(_setStep))),
            if (step == IssueStep.waitingCard)
              FlexButton(
                label: 'Batal',
                variant: ShadButtonVariant.outline,
                onPressed: () {
                  _nfc.stop();
                  setState(() => _step = null);
                },
              ),
            if (step == IssueStep.done) FlexButton(label: 'Selesai', onPressed: () => Navigator.of(context).pop(true)),
          ],
        ),
      ),
    );
  }
}
