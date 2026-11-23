import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/format.dart';
import '../../widgets/badges.dart';
import '../../widgets/member_card.dart';
import '../../widgets/ui.dart';
import 'event_detail_page.dart';
import 'member_pages.dart';
import 'my_events_tab.dart';
import 'my_qr_tab.dart';

/// Beranda anggota ala aplikasi bank: sapaan, kartu anggota (ketuk → QR), menu cepat,
/// ringkasan kehadiran, kegiatan terdekat, dan pengumuman.
class HomeTab extends StatefulWidget {
  const HomeTab({super.key, required this.api, required this.name, this.onRegister, this.onOpenTab, this.onOpenCard});

  final ApiClient api;
  final String name;

  /// Kartu kosong diketuk (isi e-profil). Mengembalikan true bila e-profil terkirim.
  final Future<bool?> Function(BuildContext context)? onRegister;

  /// Pindah ke tab lain di layar utama (1 = Kegiatan, 2 = Riwayat, 3 = Akun).
  final void Function(int tab)? onOpenTab;

  /// Buka halaman kelola kartu (daftar kartu NFC, unduh PDF, lapor kartu).
  final Future<void> Function(BuildContext context)? onOpenCard;

  @override
  State<HomeTab> createState() => HomeTabState();
}

class HomeTabState extends State<HomeTab> {
  late Future<MemberCardInfo> _card;
  late Future<List<MemberEvent>> _events;
  late Future<MemberHistory?> _history;
  late Future<Achievements?> _achievements;
  late Future<List<Announcement>> _anns;

  @override
  void initState() {
    super.initState();
    _load();
  }

  void _load() {
    _card = widget.api.myCard();
    _events = widget.api.myEvents().catchError((_) => <MemberEvent>[]);
    _history = widget.api.myHistory().then<MemberHistory?>((h) => h).catchError((_) => null);
    _achievements = widget.api.myAchievements().then<Achievements?>((a) => a).catchError((_) => null);
    _anns = widget.api.myAnnouncements().catchError((_) => <Announcement>[]);
  }

  Future<void> refresh() async {
    setState(_load);
    await _card.catchError((_) => MemberCardInfo(state: CardState.none));
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

  Future<void> _open(String title, Widget page) async {
    await pushMemberPage<void>(context, title, page);
    if (mounted) refresh(); // mis. izin baru diajukan → Beranda ikut diperbarui
  }

  /// Detail kegiatan di halaman sendiri (jadwal, check-in, izin).
  Future<void> _eventDetail(MemberEvent e) async {
    await pushMemberPage<void>(context, 'Detail kegiatan', EventDetailPage(api: widget.api, event: e));
    if (mounted) refresh();
  }

  void _announcementDetail(Announcement a) {
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (sheet) {
        final theme = ShadTheme.of(sheet);
        return SafeArea(
          child: ConstrainedBox(
            constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(sheet).height * 0.8),
            child: ListView(
              shrinkWrap: true,
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
              children: [
                Text(a.title, style: theme.textTheme.h4),
                const SizedBox(height: 4),
                Text([if (a.createdAt != null) fmtDate(a.createdAt!), if (a.division != null) 'Divisi ${a.division}'].join(' · '), style: theme.textTheme.muted),
                const SizedBox(height: 12),
                SelectableText(a.body, style: theme.textTheme.p),
              ],
            ),
          ),
        );
      },
    );
  }

  Future<void> _openCard() async {
    await widget.onOpenCard?.call(context);
    if (mounted) refresh();
  }

  String get _firstName {
    final parts = widget.name.trim().split(RegExp(r'\s+'));
    return parts.isEmpty || parts.first.isEmpty ? 'Anggota' : parts.first;
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return RefreshIndicator(
      onRefresh: refresh,
      child: FutureBuilder<MemberCardInfo>(
        future: _card,
        builder: (context, snap) {
          final info = snap.data;
          final children = <Widget>[Text('Halo, $_firstName 👋', style: theme.textTheme.h3, maxLines: 2, overflow: TextOverflow.ellipsis), const SizedBox(height: 16)];
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
            if (info!.state == CardState.active) {
              // HP: satu kolom. Tablet/laptop: kartu & menu cepat di kiri, ringkasan di kanan.
              children.add(
                ResponsiveColumns(
                  leftFlex: 5,
                  rightFlex: 6,
                  left: [..._cardSection(context, info), const SizedBox(height: 20), _quickMenu(context), const SizedBox(height: 20)],
                  right: [_attendance(context), _upcoming(context), _announcements(context)],
                ),
              );
            } else {
              // Belum aktif: kartu + keterangan di tengah, tidak membentang di layar lebar.
              children.add(
                Center(
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 520),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: _cardSection(context, info)),
                  ),
                ),
              );
            }
          }
          return ListView(padding: const EdgeInsets.fromLTRB(16, 16, 16, 24), physics: const AlwaysScrollableScrollPhysics(), children: children);
        },
      ),
    );
  }

  List<Widget> _cardSection(BuildContext context, MemberCardInfo info) {
    final theme = ShadTheme.of(context);
    final card = MemberCardView(
      info: info,
      token: widget.api.token,
      onRegister: widget.onRegister == null
          ? null
          : () async {
              final sent = await widget.onRegister!(context);
              if (sent == true) refresh();
            },
    );
    switch (info.state) {
      case CardState.active:
        return [
          Semantics(
            button: true,
            hint: 'Ketuk untuk menampilkan QR',
            child: GestureDetector(onTap: _showQr, child: card),
          ),
          const SizedBox(height: 8),
          Text(
            info.cardLast4 != null ? 'Ketuk kartu untuk menampilkan QR · kartu NFC aktif' : 'Ketuk kartu untuk menampilkan QR · kartu NFC belum terdaftar',
            style: theme.textTheme.muted.copyWith(fontSize: 12),
            textAlign: TextAlign.center,
          ),
          if (info.pendingRequestReason != null || info.lastDecision != null) ...[
            const SizedBox(height: 12),
            _notice(
              context,
              icon: info.pendingRequestReason != null ? LucideIcons.hourglass : LucideIcons.bellRing,
              text: info.pendingRequestReason != null ? 'Laporan kartu Anda sedang ditindaklanjuti pengurus.' : 'Ada keputusan pengurus atas laporan kartu Anda.',
              onTap: _openCard,
            ),
          ] else if (info.cardLast4 == null && info.canWriteCard) ...[
            const SizedBox(height: 12),
            _notice(context, icon: LucideIcons.nfc, text: 'Daftarkan kartu NFC Anda sendiri (sekali).', onTap: _openCard),
          ],
        ];
      case CardState.none:
        return [
          card,
          const SizedBox(height: 16),
          ShadAlert(
            icon: const Icon(LucideIcons.info),
            title: const Text('Belum terdaftar sebagai anggota'),
            description: Text(widget.onRegister == null ? 'Hubungi pengurus untuk mendaftarkan keanggotaan Anda.' : 'Ketuk kartu di atas untuk mengisi e-profil.'),
          ),
        ];
      case CardState.pending:
        return [
          card,
          const SizedBox(height: 16),
          const ShadAlert(
            icon: Icon(LucideIcons.hourglass),
            title: Text('Menunggu pengesahan pusat'),
            description: Text('E-profil Anda sedang diperiksa pengurus. Kartu aktif otomatis setelah disahkan — tarik layar untuk memperbarui.'),
          ),
        ];
      case CardState.inactive:
        return [
          card,
          const SizedBox(height: 16),
          ShadAlert(
            icon: const Icon(LucideIcons.info),
            title: Text(info.status == 'alumni' ? 'Status: alumni' : 'Keanggotaan tidak aktif'),
            description: const Text('Kartu tidak bisa dipakai untuk absensi periode ini. Hubungi pengurus bila ini keliru.'),
          ),
        ];
    }
  }

  Widget _notice(BuildContext context, {required IconData icon, required String text, required VoidCallback onTap}) {
    final theme = ShadTheme.of(context);
    return Material(
      color: theme.colorScheme.muted,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          child: Row(
            children: [
              Icon(icon, size: 18, color: theme.colorScheme.foreground),
              const SizedBox(width: 10),
              Expanded(child: Text(text, style: theme.textTheme.small)),
              Icon(LucideIcons.chevronRight, size: 18, color: theme.colorScheme.mutedForeground),
            ],
          ),
        ),
      ),
    );
  }

  Widget _quickMenu(BuildContext context) {
    final items = <(IconData, String, VoidCallback)>[
      (LucideIcons.qrCode, 'QR Saya', _showQr),
      (LucideIcons.calendarPlus, 'Izin', () => _open('Ajukan izin', ExcuseListPage(api: widget.api))),
      (LucideIcons.fileText, 'Rapor', () => _open('Rapor kehadiran', ReportPage(api: widget.api))),
      (LucideIcons.creditCard, 'Kartu', _openCard),
      (LucideIcons.wallet, 'Denda', () => _open('Denda', FinesPage(api: widget.api))),
      (LucideIcons.calendarSync, 'Jadwal', () => showCalendarSubscription(context, widget.api)),
      (LucideIcons.idCard, 'Data Diri', () => _open('Data keanggotaan', MembershipPage(api: widget.api))),
      (LucideIcons.circleHelp, 'Panduan', () => _open('Panduan anggota', const HelpPage())),
    ];
    // Dua baris × empat menu, seperti menu cepat aplikasi bank.
    return Column(
      children: [
        for (var r = 0; r < items.length; r += 4)
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              for (final (icon, label, onTap) in items.skip(r).take(4))
                Expanded(
                  child: QuickAction(icon: icon, label: label, onTap: onTap),
                ),
            ],
          ),
      ],
    );
  }

  /// Bagian di kolom ringkasan: kartu seragam dengan judul & "Lihat semua" rata kanan.
  Widget _section(BuildContext context, String title, {VoidCallback? onMore, required List<Widget> children}) => Padding(
    padding: const EdgeInsets.only(bottom: 20),
    child: SectionCard(
      title: title,
      // Teks tautan biasa (bukan tombol bertinggi tetap) agar bisa turun baris pada huruf besar.
      trailing: onMore == null
          ? null
          : Semantics(
              button: true,
              child: InkWell(
                borderRadius: BorderRadius.circular(6),
                onTap: onMore,
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Text('Lihat semua', style: ShadTheme.of(context).textTheme.small.copyWith(fontWeight: FontWeight.w600)),
                ),
              ),
            ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: children),
    ),
  );

  Widget _attendance(BuildContext context) {
    final theme = ShadTheme.of(context);
    return FutureBuilder<MemberHistory?>(
      future: _history,
      builder: (context, snap) {
        final h = snap.data;
        if (h == null) return const SizedBox.shrink();
        final rate = h.rate;
        return Padding(
          padding: const EdgeInsets.only(bottom: 20),
          child: Material(
            color: theme.colorScheme.card,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(14),
              side: BorderSide(color: theme.colorScheme.border),
            ),
            child: InkWell(
              borderRadius: BorderRadius.circular(14),
              onTap: () => _open('Rapor kehadiran', ReportPage(api: widget.api)),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(child: Text('Kehadiran${h.term != null ? ' periode ${h.term}' : ''}', style: theme.textTheme.muted)),
                        Text(rate == null ? '–' : '${rate.toStringAsFixed(rate % 1 == 0 ? 0 : 1)}%', style: theme.textTheme.h3),
                      ],
                    ),
                    const SizedBox(height: 10),
                    ClipRRect(
                      borderRadius: BorderRadius.circular(4),
                      child: LinearProgressIndicator(
                        value: ((rate ?? 0) / 100).clamp(0, 1),
                        minHeight: 8,
                        backgroundColor: theme.colorScheme.muted,
                        color: h.atRisk ? theme.colorScheme.destructive : theme.colorScheme.primary,
                      ),
                    ),
                    const SizedBox(height: 10),
                    Text(
                      [
                        '${h.total} kegiatan',
                        'hadir ${h.byStatus['hadir'] ?? 0}',
                        'telat ${h.byStatus['telat'] ?? 0}',
                        'alpa ${h.byStatus['alpa'] ?? 0}',
                        if (h.finesUnpaid > 0) 'denda ${rupiah(h.finesUnpaid)}',
                      ].join(' · '),
                      style: theme.textTheme.small,
                    ),
                    if (h.atRisk) ...[const SizedBox(height: 6), Text('Kehadiran Anda perlu perhatian.', style: theme.textTheme.small.copyWith(color: theme.colorScheme.destructive))],
                    // Poin & lencana (ketuk → halaman lencana).
                    FutureBuilder<Achievements?>(
                      future: _achievements,
                      builder: (context, a) => a.data == null
                          ? const SizedBox.shrink()
                          : Padding(
                              padding: const EdgeInsets.only(top: 10),
                              child: InkWell(
                                borderRadius: BorderRadius.circular(8),
                                onTap: () => _open(
                                  'Poin & lencana',
                                  ListView(padding: const EdgeInsets.all(16), children: [SectionCard(child: BadgesView(data: a.data!))]),
                                ),
                                child: Padding(
                                  padding: const EdgeInsets.symmetric(vertical: 4),
                                  child: Row(
                                    children: [
                                      Icon(LucideIcons.award, size: 16, color: theme.colorScheme.foreground),
                                      const SizedBox(width: 6),
                                      Expanded(child: Text('${a.data!.points} poin · ${a.data!.earned} lencana', style: theme.textTheme.small.copyWith(fontWeight: FontWeight.w600))),
                                      Icon(LucideIcons.chevronRight, size: 16, color: theme.colorScheme.mutedForeground),
                                    ],
                                  ),
                                ),
                              ),
                            ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _upcoming(BuildContext context) {
    final theme = ShadTheme.of(context);
    return FutureBuilder<List<MemberEvent>>(
      future: _events,
      builder: (context, snap) {
        final now = DateTime.now();
        final list = [...?snap.data?.where((e) => !e.closed && e.checkinClosesAt.isAfter(now))]..sort((a, b) => a.startsAt.compareTo(b.startsAt));
        if (snap.connectionState != ConnectionState.done) return const SizedBox.shrink();
        return _section(
          context,
          'Kegiatan terdekat',
          onMore: () => widget.onOpenTab?.call(1),
          children: list.isEmpty
              ? [Text('Belum ada kegiatan terjadwal.', style: theme.textTheme.muted)]
              : [
                  for (final e in list.take(3))
                    _row(
                      context,
                      icon: LucideIcons.calendarDays,
                      title: e.title,
                      subtitle: [fmtDateTime(e.startsAt), if (e.location != null) e.location!].join(' · '),
                      trailing: MyEventsTabStatus.of(e),
                      onTap: () => _eventDetail(e),
                    ),
                ],
        );
      },
    );
  }

  Widget _announcements(BuildContext context) => FutureBuilder<List<Announcement>>(
    future: _anns,
    builder: (context, snap) {
      final list = snap.data ?? const <Announcement>[];
      if (list.isEmpty) return const SizedBox.shrink();
      return _section(
        context,
        'Pengumuman',
        onMore: () => widget.onOpenTab?.call(1),
        children: [for (final a in list.take(2)) _row(context, icon: a.pinned ? LucideIcons.pin : LucideIcons.megaphone, title: a.title, subtitle: a.body, onTap: () => _announcementDetail(a))],
      );
    },
  );

  Widget _row(BuildContext context, {required IconData icon, required String title, required String subtitle, String? trailing, VoidCallback? onTap}) {
    final theme = ShadTheme.of(context);
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 36,
              height: 36,
              decoration: BoxDecoration(color: theme.colorScheme.muted, borderRadius: BorderRadius.circular(10)),
              child: Icon(icon, size: 18, color: theme.colorScheme.foreground),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: theme.textTheme.small.copyWith(fontWeight: FontWeight.w600),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                  Text(subtitle, style: theme.textTheme.muted, maxLines: 2, overflow: TextOverflow.ellipsis),
                  if (trailing != null) ...[const SizedBox(height: 4), ShadBadge.secondary(child: Text(trailing))],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
