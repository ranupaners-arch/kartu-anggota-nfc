import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/server_config.dart';
import '../../app/session.dart';
import '../../widgets/ui.dart';
import '../connect_screen.dart';
import '../login_screen.dart';
import 'account_tab.dart';
import 'eprofile_screen.dart';
import 'home_tab.dart';
import 'my_card_tab.dart';
import 'my_events_tab.dart';
import 'my_history_tab.dart';

/// Layar utama anggota ala aplikasi bank: Beranda (kartu + menu cepat), Kegiatan, Riwayat, Akun.
class MemberHomeScreen extends StatefulWidget {
  const MemberHomeScreen({super.key, required this.session, this.api});

  final Session session;

  /// Untuk tes.
  final ApiClient? api;

  @override
  State<MemberHomeScreen> createState() => _MemberHomeScreenState();
}

class _MemberHomeScreenState extends State<MemberHomeScreen> {
  late final ApiClient _api = widget.api ?? widget.session.client();
  final _home = GlobalKey<HomeTabState>();
  int _tab = 0;

  /// Nama organisasi untuk subjudul bilah atas (sama dengan portal web).
  String? _org;

  @override
  void initState() {
    super.initState();
    _api.myCard().then((c) {
      if (mounted) setState(() => _org = c.organization);
    }, onError: (_) {});
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
    actions: [ShadIconButton.ghost(icon: const Icon(LucideIcons.logOut, size: 18), onPressed: _confirmLogout)],
    child: child,
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

  static const _destinations = [(LucideIcons.house, 'Beranda'), (LucideIcons.calendarDays, 'Kegiatan'), (LucideIcons.history, 'Riwayat'), (LucideIcons.userRound, 'Akun')];

  void _select(int i) {
    if (i == 0 && _tab != 0) _home.currentState?.refresh(); // kembali ke Beranda → data segar
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
