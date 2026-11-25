import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/server_config.dart';
import '../../app/format.dart';
import '../../app/session.dart';
import '../../app/session_watch.dart';
import '../../notify/phone_notices.dart';
import '../../widgets/ui.dart';
import '../connect_screen.dart';
import '../events_screen.dart';
import '../login_screen.dart';
import 'account_tab.dart';
import 'eprofile_screen.dart';
import 'home_tab.dart';
import 'member_pages.dart';
import 'my_card_tab.dart';
import 'certificates_page.dart';
import 'loans_page.dart';
import 'minutes_page.dart';
import 'my_events_tab.dart';
import 'my_history_tab.dart';
import 'notifications_page.dart';
import 'surveys_page.dart';
import 'profile_change_page.dart';

/// Layar utama anggota ala aplikasi bank: Beranda (kartu + menu cepat), Kegiatan, Riwayat, Akun.
class MemberHomeScreen extends StatefulWidget {
  const MemberHomeScreen({super.key, required this.session, this.api});

  final Session session;

  /// Untuk tes.
  final ApiClient? api;

  @override
  State<MemberHomeScreen> createState() => _MemberHomeScreenState();
}

class _MemberHomeScreenState extends State<MemberHomeScreen> with SessionWatch<MemberHomeScreen> {
  late final ApiClient _api = widget.api ?? widget.session.client();

  @override
  Session get watchedSession => widget.session;

  @override
  ApiClient get watchedApi => _api;
  final _home = GlobalKey<HomeTabState>();
  int _tab = 0;

  /// Nama organisasi untuk subjudul bilah atas (sama dengan portal web).
  String? _org;

  /// Kotak notifikasi: daftar terbaru & kapan terakhir dibuka (angka di lonceng).
  List<MemberNotice> _notices = const [];
  DateTime? _seenAt;

  @override
  void initState() {
    super.initState();
    _api.myCard().then((c) {
      if (mounted) setState(() => _org = c.organization);
    }, onError: (_) {});
    _loadNotices();
    PhoneNotices.attach(() {
      if (mounted) _openNotices();
    });
  }

  Future<void> _loadNotices() async {
    try {
      final seen = await NoticeSeen.load();
      final list = await _api.myNotifications();
      if (mounted) {
        setState(() {
          _notices = list;
          _seenAt = seen;
        });
      }
    } catch (_) {
      // Belum anggota / tanpa koneksi: lonceng tanpa angka.
    }
  }

  Future<void> _openNotices() async {
    final before = _seenAt;
    final now = DateTime.now();
    await NoticeSeen.save(now);
    await PhoneNotices.clearShown();
    setState(() => _seenAt = now);
    if (!mounted) return;
    await pushMemberPage<void>(context, 'Notifikasi', NotificationsPage(api: _api, seenBefore: before, onOpen: _openNotice));
    _loadNotices();
  }

  /// Notifikasi diketuk → halaman terkait (bukan pindah tab).
  Future<void> _openNotice(BuildContext context, MemberNotice n) async {
    switch (n.type) {
      case 'excuse' || 'reminder':
        await pushMemberPage<void>(context, 'Ajukan izin', ExcuseListPage(api: _api));
      case 'card':
        await _openCard(context);
      case 'profile':
        await pushMemberPage<void>(context, 'Ubah data diri', ProfileChangePage(api: _api));
      case 'rsvp':
        await pushMemberPage<void>(context, 'Kegiatan', MyEventsTab(api: _api));
      case 'loan':
        await pushMemberPage<void>(context, 'Barang dipinjam', LoansPage(api: _api));
      case 'survey':
        await pushMemberPage<void>(context, 'Survei kegiatan', SurveysPage(api: _api));
      case 'minutes':
        await pushMemberPage<void>(context, 'Notulen & dokumen', MinutesPage(api: _api));
      case 'certificate':
        await pushMemberPage<void>(context, 'Sertifikat', CertificatesPage(api: _api));
      case 'due':
        await pushMemberPage<void>(context, 'Riwayat', MyHistoryTab(api: _api));
      case 'fine':
        await pushMemberPage<void>(context, 'Denda', FinesPage(api: _api));
      case 'announcement':
        final id = int.tryParse(n.id.split(':').last);
        final all = await _api.myAnnouncements().catchError((_) => <Announcement>[]);
        final a = all.where((x) => x.id == id).firstOrNull;
        if (!context.mounted) return;
        await pushMemberPage<void>(context, 'Pengumuman', AnnouncementPage(title: n.title, body: a?.body ?? n.body ?? '', at: a?.createdAt ?? n.at, division: a?.division));
    }
  }

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
    if (ok == true) await _logout();
  }

  /// Bilah atas seragam untuk keempat tab: logo, judul, nama organisasi, tombol keluar.
  Widget _tabPage(String title, Widget child) => AppPage(
    title: title,
    maxContentWidth: kWideContentWidth,
    subtitle: _org,
    brand: true,
    actions: [
      NoticeBell(unread: NoticeSeen.unread(_notices, _seenAt), onPressed: _openNotices),
      ShadIconButton.ghost(icon: const Icon(LucideIcons.logOut, size: 18), onPressed: _confirmLogout),
    ],
    child: ValueListenableBuilder<DateTime?>(
      valueListenable: _api.offlineSince,
      builder: (context, since, _) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (since != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
              child: ShadAlert(
                icon: const Icon(LucideIcons.cloudOff),
                title: const Text('Mode offline'),
                description: Text(
                  'Menampilkan data terakhir (${fmtDateTime(since)}).${widget.session.isMemberOnDuty ? ' Scan kartu ada di tab Tugas.' : ' QR Saya dan pengajuan izin butuh internet.'}',
                ),
              ),
            ),
          Expanded(child: child),
        ],
      ),
    ),
  );

  Future<void> _logout() async {
    await _api.logout();
    await Session.clear();
    final server = await ServerConfig.load();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(MaterialPageRoute<void>(builder: (_) => server == null ? const ConnectScreen() : LoginScreen(server: server)), (_) => false);
  }

  Future<bool?> _register(BuildContext context) => Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => EProfileScreen(api: _api)));

  /// Halaman kelola kartu (daftar kartu NFC sendiri, unduh PDF, lapor kartu, QR cadangan).
  Future<void> _openCard(BuildContext context) => Navigator.of(context).push<void>(
    MaterialPageRoute(
      builder: (_) => Scaffold(
        body: AppPage(
          title: 'Kartu Saya',
          maxContentWidth: 560,
          child: MyCardTab(api: _api, name: widget.session.userName, onRegister: _register),
        ),
      ),
    ),
  );

  void _openTab(int i) => setState(() => _tab = i);

  /// Tab bawah; anggota yang sedang bertugas mendapat tab "Tugas" (beranda petugas) sebelum Akun.
  List<(IconData, String)> get _destinations => [
    (LucideIcons.house, 'Beranda'),
    (LucideIcons.calendarDays, 'Kegiatan'),
    (LucideIcons.history, 'Riwayat'),
    if (widget.session.isMemberOnDuty) (LucideIcons.scanLine, 'Tugas'),
    (LucideIcons.userRound, 'Akun'),
  ];

  void _select(int i) {
    if (i == 0 && _tab != 0) {
      _home.currentState?.refresh(); // kembali ke Beranda → data segar
      _loadNotices();
    }
    setState(() => _tab = i);
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    final tabs = IndexedStack(
      index: _tab,
      children: [
        _tabPage('Beranda', HomeTab(key: _home, api: _api, name: widget.session.userName, onRegister: _register, onOpenTab: _openTab, onOpenCard: _openCard)),
        _tabPage('Kegiatan', MyEventsTab(api: _api)),
        _tabPage('Riwayat', MyHistoryTab(api: _api)),
        if (widget.session.isMemberOnDuty) EventsScreen(session: widget.session, api: _api, embedded: true),
        _tabPage('Akun', AccountTab(api: _api, name: widget.session.userName, onLogout: _logout, onOpenCard: _openCard)),
      ],
    );
    // HP & tablet tegak: navigasi bawah. Laptop / tablet mendatar (≥ 1024): navigasi samping.
    final rail = MediaQuery.sizeOf(context).width >= 1024;
    // Tombol kembali HP: dari tab lain → kembali ke Beranda dulu; di Beranda → keluar app.
    return PopScope(
      canPop: _tab == 0,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) setState(() => _tab = 0);
      },
      child: Scaffold(
        backgroundColor: theme.colorScheme.background,
        body: rail
            ? Row(
                children: [
                  SafeArea(
                    right: false,
                    child: NavigationRail(
                      selectedIndex: _tab,
                      onDestinationSelected: _select,
                      labelType: NavigationRailLabelType.all,
                      groupAlignment: -1,
                      minWidth: 88,
                      destinations: [for (final (icon, label) in _destinations) NavigationRailDestination(icon: Icon(icon), label: Text(label))],
                    ),
                  ),
                  VerticalDivider(width: 1, color: theme.colorScheme.border),
                  Expanded(child: tabs),
                ],
              )
            : tabs,
        bottomNavigationBar: rail
            ? null
            : NavigationBar(
                selectedIndex: _tab,
                onDestinationSelected: _select,
                destinations: [for (final (icon, label) in _destinations) NavigationDestination(icon: Icon(icon), label: label)],
              ),
      ),
    );
  }
}
