import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../widgets/member_card.dart';
import '../../widgets/ui.dart';
import 'my_qr_tab.dart';

/// Tab "Kartu": kartu anggota di halaman utama. Kosong → ajakan isi e-profil; menunggu → info
/// pengesahan; aktif → kartu lengkap + QR cadangan (kartu tertinggal).
class MyCardTab extends StatefulWidget {
  const MyCardTab({super.key, required this.api, required this.name, this.onRegister});

  final ApiClient api;
  final String name;

  /// Dipanggil saat kartu kosong diketuk (isi e-profil). Mengembalikan true bila e-profil terkirim.
  final Future<bool?> Function(BuildContext context)? onRegister;

  @override
  State<MyCardTab> createState() => _MyCardTabState();
}

class _MyCardTabState extends State<MyCardTab> {
  late Future<MemberCardInfo> _card = widget.api.myCard();

  Future<void> _refresh() async {
    setState(() => _card = widget.api.myCard());
    await _card.catchError((_) => MemberCardInfo(state: CardState.none));
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
                children.add(FlexButton(label: 'Tampilkan QR (kartu tertinggal)', icon: LucideIcons.qrCode, variant: ShadButtonVariant.outline, onPressed: _showQr));
                children.add(const SizedBox(height: 8));
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
