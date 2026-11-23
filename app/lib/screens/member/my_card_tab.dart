import 'package:material_ui/material_ui.dart';
import 'package:nfc_manager/nfc_manager.dart' show NfcAvailability;
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/device_settings.dart';
import '../../app/pdf.dart';
import '../../nfc/nfc_service.dart';
import '../../widgets/member_card.dart';
import '../../widgets/ui.dart';
import 'my_qr_tab.dart';
import 'self_card_screen.dart';

/// Tab "Kartu": kartu anggota di halaman utama. Kosong → ajakan isi e-profil; menunggu → info
/// pengesahan; aktif → kartu lengkap + QR cadangan (kartu tertinggal).
class MyCardTab extends StatefulWidget {
  const MyCardTab({super.key, required this.api, required this.name, this.onRegister, this.selfCardScreen, this.nfcAvailability, this.openPdf});

  final ApiClient api;
  final String name;

  /// Dipanggil saat kartu kosong diketuk (isi e-profil). Mengembalikan true bila e-profil terkirim.
  final Future<bool?> Function(BuildContext context)? onRegister;

  /// Untuk tes: layar daftar kartu sendiri (bawaan: SelfCardScreen dengan NFC asli).
  final Widget Function(ApiClient api)? selfCardScreen;

  /// Untuk tes: status NFC HP (bawaan: dicek lewat NfcService).
  final Future<NfcAvailability> Function()? nfcAvailability;

  /// Untuk tes: penyimpan/pembuka PDF (bawaan: simpan sementara lalu buka penampil PDF HP).
  final Future<String?> Function(List<int> bytes)? openPdf;

  @override
  State<MyCardTab> createState() => _MyCardTabState();
}

class _MyCardTabState extends State<MyCardTab> {
  late Future<MemberCardInfo> _card = widget.api.myCard();
  late Future<NfcAvailability> _nfc = _checkNfc();

  Future<NfcAvailability> _checkNfc() => (widget.nfcAvailability ?? () => NfcService().availability())().catchError((_) => NfcAvailability.unsupported);

  Future<void> _refresh() async {
    setState(() {
      _card = widget.api.myCard();
      _nfc = _checkNfc();
    });
    await _card.catchError((_) => MemberCardInfo(state: CardState.none));
  }

  Future<void> _registerOwnCard() async {
    final ok = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => widget.selfCardScreen?.call(widget.api) ?? SelfCardScreen(api: widget.api)),
    );
    if (ok == true) _refresh();
  }

  Future<void> _report([String reason = 'hilang']) async {
    final sent = await showShadDialog<bool>(context: context, builder: (_) => _ReportDialog(api: widget.api, initialReason: reason));
    if (sent == true) _refresh();
  }

  bool _downloading = false;
  String? _downloadError;

  /// Unduh PDF kartu sendiri (depan & belakang) untuk dicetak di kertas/PVC.
  Future<void> _downloadCard() async {
    setState(() {
      _downloading = true;
      _downloadError = null;
    });
    try {
      final bytes = await widget.api.downloadBytes('${widget.api.baseUrl}/api/me/card.pdf');
      final err = await (widget.openPdf ?? (b) => saveAndOpenPdf(b, fileName: 'kartu-anggota.pdf'))(bytes);
      if (err != null && mounted) setState(() => _downloadError = err);
    } catch (e) {
      if (mounted) setState(() => _downloadError = e is ApiException ? e.message : 'Gagal mengunduh kartu.');
    } finally {
      if (mounted) setState(() => _downloading = false);
    }
  }

  Future<void> _turnOnNfc() async {
    await openNfcSettings();
    if (mounted) setState(() => _nfc = _checkNfc()); // dicek ulang saat kembali
  }

  /// Bagian "daftarkan kartu NFC sendiri" sesuai kemampuan NFC HP ini.
  Widget _ownCardSection(BuildContext context) => FutureBuilder<NfcAvailability>(
    future: _nfc,
    builder: (context, snap) {
      if (snap.connectionState != ConnectionState.done) return const SizedBox.shrink();
      return switch (snap.data) {
        NfcAvailability.enabled => FlexButton(label: 'Daftarkan kartu NFC saya', icon: LucideIcons.nfc, onPressed: _registerOwnCard),
        NfcAvailability.disabled => ShadAlert(
          icon: const Icon(LucideIcons.nfc),
          title: const Text('NFC di HP ini sedang mati'),
          description: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            spacing: 8,
            children: [
              const Text('Nyalakan NFC untuk mendaftarkan kartu Anda sendiri.'),
              ShadButton.outline(size: ShadButtonSize.sm, onPressed: _turnOnNfc, child: const Text('Buka pengaturan NFC')),
            ],
          ),
        ),
        _ => ShadAlert(
          icon: const Icon(LucideIcons.smartphone),
          title: const Text('HP ini tidak mendukung NFC'),
          description: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            spacing: 8,
            children: [
              const Text('Pilihan: minta pengurus menerbitkan kartu Anda, atau masuk dengan akun Anda di HP lain yang ber-NFC (mis. HP teman/sekretariat) untuk mendaftarkan kartu. Sementara itu absen pakai QR.'),
              ShadButton.outline(size: ShadButtonSize.sm, onPressed: () => _report('tanpa_nfc'), child: const Text('Minta pengurus terbitkan kartu')),
            ],
          ),
        ),
      };
    },
  );

  static const _reasonLabel = {'hilang': 'kartu hilang', 'rusak': 'kartu rusak', 'tanpa_nfc': 'HP tanpa NFC'};

  /// Keputusan pengurus atas laporan terakhir.
  Widget _decision(({String reason, String status, String? resolution, String? note}) d, bool canWrite) {
    final note = d.note == null || d.note!.isEmpty ? '' : ' Catatan: ${d.note}';
    if (d.status == 'rejected') {
      return ShadAlert(icon: const Icon(LucideIcons.circleX), title: const Text('Laporan kartu ditolak'), description: Text('Hubungi pengurus bila perlu.$note'));
    }
    return ShadAlert(
      icon: const Icon(LucideIcons.circleCheck),
      title: Text(d.resolution == 'admin' ? 'Pengurus akan menerbitkan kartu Anda' : 'Laporan disetujui'),
      description: Text(d.resolution == 'admin' ? 'Kartu baru disiapkan pengurus.$note' : '${canWrite ? 'Silakan daftarkan kartu baru di bawah.' : 'Kartu baru sudah terdaftar.'}$note'),
    );
  }

  void _showQr() => Navigator.of(context).push(
    MaterialPageRoute<void>(
      builder: (_) => Scaffold(
        body: AppPage(title: 'QR Saya', maxContentWidth: 520, child: MyQrTab(api: widget.api, name: widget.name)),
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
                if (info.lastDecision case final d?) {
                  children.add(_decision(d, info.canWriteCard));
                  children.add(const SizedBox(height: 8));
                }
                if (info.canWriteCard && info.pendingRequestReason == null) {
                  children.add(_ownCardSection(context));
                  children.add(const SizedBox(height: 8));
                }
                children.add(FlexButton(label: 'Tampilkan QR (kartu tertinggal)', icon: LucideIcons.qrCode, variant: ShadButtonVariant.outline, onPressed: _showQr));
                children.add(const SizedBox(height: 8));
                children.add(
                  FlexButton(
                    label: _downloading ? 'Mengunduh kartu…' : 'Unduh kartu (PDF) untuk dicetak',
                    icon: LucideIcons.fileDown,
                    variant: ShadButtonVariant.outline,
                    enabled: !_downloading,
                    onPressed: _downloadCard,
                  ),
                );
                if (_downloadError != null) children.addAll([const SizedBox(height: 8), ErrorBanner(_downloadError!)]);
                children.add(const SizedBox(height: 8));
                if (info.pendingRequestReason != null) {
                  children.add(
                    ShadAlert(
                      icon: const Icon(LucideIcons.hourglass),
                      title: const Text('Laporan kartu menunggu tindak lanjut pengurus'),
                      description: Text('Laporan: ${_reasonLabel[info.pendingRequestReason] ?? info.pendingRequestReason}. Keputusan pengurus akan tampil di sini.'),
                    ),
                  );
                  children.add(const SizedBox(height: 8));
                } else if (info.canRequestCard) {
                  children.add(FlexButton(label: 'Lapor kartu hilang/rusak', icon: LucideIcons.triangleAlert, variant: ShadButtonVariant.ghost, small: true, onPressed: _report));
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

/// Lapor kartu: hilang (kartu lama langsung diblokir), rusak, atau HP tanpa NFC / NFC rusak
/// (minta pengurus menerbitkan kartu). Pengurus menindaklanjuti; keputusannya tampil di tab Kartu.
class _ReportDialog extends StatefulWidget {
  const _ReportDialog({required this.api, required this.initialReason});

  final ApiClient api;
  final String initialReason;

  @override
  State<_ReportDialog> createState() => _ReportDialogState();
}

class _ReportDialogState extends State<_ReportDialog> {
  late String _reason = widget.initialReason;
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
    title: const Text('Lapor kartu'),
    description: const Text('Laporan dikirim ke pengurus untuk ditindaklanjuti. Sementara itu Anda tetap bisa absen dengan QR.'),
    actions: [
      ShadButton.outline(onPressed: () => Navigator.of(context).pop(false), child: const Text('Batal')),
      ShadButton(enabled: !_busy, onPressed: _busy ? null : _send, child: Text(_busy ? 'Mengirim…' : 'Kirim laporan')),
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
              ShadRadio(value: 'hilang', label: Text('Kartu hilang — kartu lama langsung diblokir')),
              ShadRadio(value: 'rusak', label: Text('Kartu rusak / tidak terbaca')),
              ShadRadio(value: 'tanpa_nfc', label: Text('HP saya tanpa NFC / NFC rusak — minta pengurus terbitkan kartu')),
            ],
          ),
          ShadInput(controller: _note, placeholder: const Text('Catatan (opsional), mis. kapan bisa ambil kartu'), maxLines: 2),
        ],
      ),
    ),
  );
}
