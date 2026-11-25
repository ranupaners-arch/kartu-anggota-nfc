import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../widgets/member_photo.dart';
import '../../widgets/ui.dart';
import 'member_pages.dart';
import 'profile_change_page.dart';
import 'certificates_page.dart';
import 'loans_page.dart';
import 'minutes_page.dart';
import 'surveys_page.dart';
import 'my_events_tab.dart' show showCalendarSubscription;

/// Tab "Akun": identitas anggota dan menu berkelompok. Tiap menu membuka halamannya sendiri.
class AccountTab extends StatefulWidget {
  const AccountTab({super.key, required this.api, required this.name, required this.onLogout, this.onOpenCard});

  final ApiClient api;
  final String name;
  final Future<void> Function() onLogout;
  final Future<void> Function(BuildContext context)? onOpenCard;

  @override
  State<AccountTab> createState() => _AccountTabState();
}

class _AccountTabState extends State<AccountTab> {
  late Future<MemberCardInfo?> _card = _load();

  Future<MemberCardInfo?> _load() => widget.api.myCard().then<MemberCardInfo?>((c) => c).catchError((_) => null);

  Future<void> _confirmLogout() async {
    final ok = await showShadDialog<bool>(
      context: context,
      builder: (context) => ShadDialog(
        title: const Text('Keluar dari akun?'),
        description: const Text('Anda perlu masuk lagi dengan email dan password untuk memakai app.'),
        actions: [
          ShadButton.outline(onPressed: () => Navigator.of(context).pop(false), child: const Text('Batal')),
          ShadButton.destructive(onPressed: () => Navigator.of(context).pop(true), child: const Text('Keluar')),
        ],
      ),
    );
    if (ok == true) await widget.onLogout();
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return FutureBuilder<MemberCardInfo?>(
      future: _card,
      builder: (context, snap) {
        final c = snap.data;
        final member = c != null && c.state != CardState.none;
        final active = c?.state == CardState.active;
        void open(String title, Widget page) => pushMemberPage<void>(context, title, page);
        return RefreshIndicator(
          onRefresh: () async {
            setState(() {
              _card = _load();
            });
            await _card;
          },
          // Daftar menu satu kolom, di tengah & tidak membentang di tablet/laptop.
          child: LayoutBuilder(
            builder: (context, box) => ListView(
              padding: EdgeInsets.symmetric(vertical: 16, horizontal: 16 + ((box.maxWidth - 640) / 2).clamp(0, double.infinity)),
              physics: const AlwaysScrollableScrollPhysics(),
              children: [
                // Kartu profil.
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: theme.colorScheme.card,
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: theme.colorScheme.border),
                  ),
                  child: Row(
                    children: [
                      ClipOval(
                        child: MemberPhoto(url: c?.photoUrl, token: widget.api.token, size: 60, name: c?.name ?? widget.name),
                      ),
                      const SizedBox(width: 14),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(c?.name ?? widget.name, style: theme.textTheme.large, maxLines: 2, overflow: TextOverflow.ellipsis),
                            if (c?.memberNo != null || c?.division != null)
                              Text(
                                [if (c?.memberNo != null) c!.memberNo!, if (c?.division != null) c!.division!].join(' · '),
                                style: theme.textTheme.muted,
                                maxLines: 2,
                                overflow: TextOverflow.ellipsis,
                              ),
                            if (c?.organization != null) Text(c!.organization!, style: theme.textTheme.muted, maxLines: 2, overflow: TextOverflow.ellipsis),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
                // Hanya menu: tiap butir membuka halamannya sendiri.
                if (member)
                  _Section('Keanggotaan', [
                    _Item(LucideIcons.idCard, 'Data keanggotaan', 'Nama, NIM, divisi, periode, status', () => open('Data keanggotaan', MembershipPage(api: widget.api))),
                    _Item(LucideIcons.userPen, 'Ubah data diri', 'Nama, no. HP, foto', () => open('Ubah data diri', ProfileChangePage(api: widget.api))),
                    if (active)
                      _Item(LucideIcons.creditCard, 'Kartu saya', 'Daftarkan kartu NFC, unduh PDF, lapor kartu', () async {
                        await widget.onOpenCard?.call(context);
                        if (mounted) {
                          setState(() {
                            _card = _load();
                          });
                        }
                      }),
                  ]),
                if (active)
                  _Section('Kehadiran', [
                    _Item(LucideIcons.calendarPlus, 'Ajukan izin', 'Izin atau sakit untuk kegiatan', () => open('Ajukan izin', ExcuseListPage(api: widget.api))),
                    _Item(LucideIcons.fileText, 'Rapor kehadiran', 'Ringkasan & unduh rapor PDF', () => open('Rapor kehadiran', ReportPage(api: widget.api))),
                    _Item(LucideIcons.star, 'Survei kegiatan', 'Nilai kegiatan tanpa nama', () => open('Survei kegiatan', SurveysPage(api: widget.api))),
                    _Item(LucideIcons.notebookText, 'Notulen & dokumen', 'Hasil rapat dan berkas kegiatan', () => open('Notulen & dokumen', MinutesPage(api: widget.api))),
                    _Item(LucideIcons.fileBadge, 'Sertifikat', 'Unduh sertifikat kegiatan', () => open('Sertifikat', CertificatesPage(api: widget.api))),
                    _Item(LucideIcons.package, 'Barang dipinjam', 'Pinjaman inventaris organisasi', () => open('Barang dipinjam', LoansPage(api: widget.api))),
                    _Item(LucideIcons.wallet, 'Denda', 'Denda absen dengan QR', () => open('Denda', FinesPage(api: widget.api))),
                    _Item(LucideIcons.calendarSync, 'Langganan jadwal', 'Jadwal otomatis di kalender HP', () => showCalendarSubscription(context, widget.api)),
                  ]),
                _Section('Keamanan', [
                  _Item(LucideIcons.keyRound, 'Ganti password', 'Minimal 10 karakter, berisi huruf dan angka', () => open('Ganti password', ChangeOwnPasswordPage(api: widget.api))),
                ]),
                _Section('Bantuan', [
                  _Item(LucideIcons.circleHelp, 'Panduan anggota', 'Cara absen, izin, kartu hilang, denda', () => open('Panduan anggota', const HelpPage())),
                  _Item(LucideIcons.info, 'Tentang aplikasi', 'Server, lisensi, hak cipta', () => open('Tentang aplikasi', AboutPage(server: widget.api.baseUrl))),
                ]),
                const SizedBox(height: 24),
                FlexButton(label: 'Keluar', icon: LucideIcons.logOut, variant: ShadButtonVariant.outline, onPressed: _confirmLogout),
              ],
            ),
          ),
        );
      },
    );
  }
}

class _Section extends StatelessWidget {
  const _Section(this.title, this.items);

  final String title;
  final List<_Item> items;

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return Padding(
      padding: const EdgeInsets.only(top: 20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.only(left: 4, bottom: 6),
            child: Text(title.toUpperCase(), style: theme.textTheme.muted.copyWith(fontSize: 12, letterSpacing: 0.8)),
          ),
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
                  for (var i = 0; i < items.length; i++) ...[if (i > 0) Divider(height: 1, indent: 52, color: theme.colorScheme.border), items[i]],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Item extends StatelessWidget {
  const _Item(this.icon, this.title, this.subtitle, this.onTap);

  final IconData icon;
  final String title, subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return InkWell(
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
            Icon(LucideIcons.chevronRight, size: 18, color: theme.colorScheme.mutedForeground),
          ],
        ),
      ),
    );
  }
}
