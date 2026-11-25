import 'package:material_ui/material_ui.dart';
import 'package:nfc_manager/nfc_manager.dart' show NfcAvailability;
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/device_settings.dart';
import '../../app/pdf.dart';
import '../../nfc/nfc_service.dart';
import '../../widgets/member_card.dart';
import '../../widgets/ui.dart';
import 'card_pages.dart';
import 'member_pages.dart' show pushMemberPage;
import 'my_qr_tab.dart';
import 'self_card_screen.dart';

/// Tab "Kartu": kartu anggota di halaman utama. Kosong → ajakan isi e-profil; menunggu → info
/// pengesahan; aktif → kartu lengkap + QR cadangan (kartu tertinggal).
class MyCardTab extends StatefulWidget {
  const MyCardTab({super.key, required this.api, required this.name, this.onRegister, this.selfCardScreen, this.nfcAvailability, this.openPdf, this.readCard});

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

  /// Untuk tes: pembaca kartu di halaman Cek kartu NFC (bawaan: NFC HP).
  final Future<String?> Function()? readCard;

  @override
  State<MyCardTab> createState() => _MyCardTabState();
}

class _MyCardTabState extends State<MyCardTab> {
  late Future<MemberCardInfo> _card = widget.api.myCard();
  late Future<NfcAvailability> _nfc = _checkNfc();

  /// Status NFC HP. Di perangkat tanpa dukungan NFC, plugin bisa langsung melempar error (bukan Future
  /// gagal) — tangkap keduanya agar halaman tetap tampil dengan pilihan "HP tidak mendukung NFC".
  Future<NfcAvailability> _checkNfc() async {
    try {
      return await (widget.nfcAvailability ?? () => NfcService().availability())();
    } catch (_) {
      return NfcAvailability.unsupported;
    }
  }

  Future<void> _refresh() async {
    setState(() {
      _card = widget.api.myCard();
      _nfc = _checkNfc();
    });
    await _card.catchError((_) => MemberCardInfo(state: CardState.none));
  }

  Future<void> _registerOwnCard() async {
    final ok = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => widget.selfCardScreen?.call(widget.api) ?? SelfCardScreen(api: widget.api)));
    if (ok == true) _refresh();
  }

  Future<void> _report([String reason = 'hilang']) async {
    final sent = await showShadDialog<bool>(
      context: context,
      builder: (_) => _ReportDialog(api: widget.api, initialReason: reason),
    );
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
              FlexButton(label: 'Buka pengaturan NFC', variant: ShadButtonVariant.outline, small: true, onPressed: _turnOnNfc),
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
              const Text(
                'Minta pengurus menerbitkan kartu, atau masuk di HP lain yang ber-NFC. Sementara itu absen pakai QR.',
              ),
              FlexButton(label: 'Minta pengurus terbitkan kartu', variant: ShadButtonVariant.outline, small: true, onPressed: () => _report('tanpa_nfc')),
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

  Future<void> _open(String title, Widget page) async {
    await pushMemberPage<void>(context, title, page);
    if (mounted) _refresh();
  }

  Widget _menu(BuildContext context, IconData icon, String title, String subtitle, VoidCallback? onTap) {
    final theme = ShadTheme.of(context);
    return Opacity(
      opacity: onTap == null ? 0.5 : 1,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 14),
          child: Row(
            children: [
              Icon(icon, size: 20, color: theme.colorScheme.foreground),
              const SizedBox(width: 18),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title, style: theme.textTheme.small.copyWith(fontWeight: FontWeight.w600)),
                    Text(subtitle, style: theme.textTheme.muted.copyWith(fontSize: 12), maxLines: 2, overflow: TextOverflow.ellipsis),
                  ],
                ),
              ),
              if (onTap != null) Icon(LucideIcons.chevronRight, size: 18, color: theme.colorScheme.mutedForeground),
            ],
          ),
        ),
      ),
    );
  }

  void _showQr() => Navigator.of(context).push(
    MaterialPageRoute<void>(
      builder: (_) => Scaffold(
        body: AppPage(
          title: 'QR Saya',
          maxContentWidth: 520,
          child: MyQrTab(api: widget.api, name: widget.name),
        ),
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
            children.add(
              const Padding(
                padding: EdgeInsets.all(32),
                child: Center(child: CircularProgressIndicator()),
              ),
            );
          } else if (snap.hasError) {
            children.add(ErrorBanner(snap.error is ApiException ? (snap.error as ApiException).message : 'Tidak bisa memuat kartu. Tarik untuk mencoba lagi.'));
          } else {
            final info = snap.data!;
            final front = MemberCardView(
              info: info,
              token: widget.api.token,
              onRegister: widget.onRegister == null
                  ? null
                  : () async {
                      final sent = await widget.onRegister!(context);
                      if (sent == true) _refresh();
                    },
            );
            if (info.state == CardState.active) {
              // Kartu aktif bisa dibalik untuk melihat sisi belakang (sama dengan kartu cetak).
              children.addAll([
                FlippableMemberCard(
                  front: front,
                  back: MemberCardBack(info: info),
                ),
                const SizedBox(height: 8),
                Text('Ketuk kartu untuk membalik', style: theme.textTheme.muted.copyWith(fontSize: 12), textAlign: TextAlign.center),
              ]);
            } else {
              children.add(front);
            }
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
                    description: Text('E-profil sedang diperiksa pengurus. Tarik layar untuk memperbarui.'),
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
                final canReport = info.canRequestCard && info.pendingRequestReason == null;
                // Aksi kartu ala aplikasi bank.
                children.add(
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: QuickAction(icon: LucideIcons.qrCode, label: 'QR Saya', onTap: _showQr),
                      ),
                      Expanded(
                        child: QuickAction(icon: LucideIcons.fileDown, label: _downloading ? 'Mengunduh…' : 'Unduh PDF', onTap: _downloading ? null : _downloadCard),
                      ),
                      Expanded(
                        child: QuickAction(
                          icon: LucideIcons.nfc,
                          label: 'Cek NFC',
                          onTap: () => _open('Cek kartu NFC', CardCheckPage(api: widget.api, readCard: widget.readCard)),
                        ),
                      ),
                      Expanded(
                        child: QuickAction(icon: LucideIcons.triangleAlert, label: 'Lapor', onTap: canReport ? _report : null),
                      ),
                    ],
                  ),
                );
                children.add(const SizedBox(height: 16));
                if (_downloadError != null) children.addAll([ErrorBanner(_downloadError!), const SizedBox(height: 12)]);
                if (info.lastDecision case final d?) {
                  children.add(_decision(d, info.canWriteCard));
                  children.add(const SizedBox(height: 12));
                }
                if (info.pendingRequestReason != null) {
                  children.add(
                    ShadAlert(
                      icon: const Icon(LucideIcons.hourglass),
                      title: const Text('Laporan kartu menunggu tindak lanjut pengurus'),
                      description: Text('Laporan: ${_reasonLabel[info.pendingRequestReason] ?? info.pendingRequestReason}. Keputusan pengurus akan tampil di sini.'),
                    ),
                  );
                  children.add(const SizedBox(height: 12));
                }
                if (info.canWriteCard && info.pendingRequestReason == null) {
                  children.add(_ownCardSection(context));
                  children.add(const SizedBox(height: 12));
                }
                // Menu kartu.
                children.add(
                  Container(
                    decoration: BoxDecoration(
                      color: theme.colorScheme.card,
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(color: theme.colorScheme.border),
                    ),
                    clipBehavior: Clip.antiAlias,
                    child: Material(
                      type: MaterialType.transparency,
                      child: Column(
                        children: [
                          _menu(context, LucideIcons.idCard, 'Detail kartu', 'Status, nomor kartu, tanggal terbit, jatah', () => _open('Detail kartu', CardDetailPage(info: info))),
                          Divider(height: 1, indent: 52, color: theme.colorScheme.border),
                          _menu(context, LucideIcons.history, 'Riwayat kartu', 'Kartu lama & laporan beserta keputusan', () => _open('Riwayat kartu', CardHistoryPage(api: widget.api))),
                          Divider(height: 1, indent: 52, color: theme.colorScheme.border),
                          _menu(
                            context,
                            LucideIcons.triangleAlert,
                            'Lapor kartu hilang/rusak',
                            canReport ? 'Kartu hilang langsung diblokir' : (info.pendingRequestReason != null ? 'Laporan sedang diproses' : 'Tidak tersedia'),
                            canReport ? _report : null,
                          ),
                        ],
                      ),
                    ),
                  ),
                );
                children.add(const SizedBox(height: 16));
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
    description: const Text('Sementara itu Anda tetap bisa absen dengan QR.'),
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
              ShadRadio(value: 'hilang', label: Text('Kartu hilang (langsung diblokir)')),
              ShadRadio(value: 'rusak', label: Text('Kartu rusak / tidak terbaca')),
              ShadRadio(value: 'tanpa_nfc', label: Text('HP tanpa NFC atau NFC rusak')),
            ],
          ),
          ShadInput(controller: _note, placeholder: const Text('Catatan (opsional), mis. kapan bisa ambil kartu'), maxLines: 2),
        ],
      ),
    ),
  );
}
