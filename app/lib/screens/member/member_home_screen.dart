import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/server_config.dart';
import '../../app/session.dart';
import '../../widgets/ui.dart';
import '../connect_screen.dart';
import '../login_screen.dart';
import 'my_events_tab.dart';
import 'my_history_tab.dart';
import 'my_qr_tab.dart';

/// Layar utama anggota: QR pribadi, kegiatan (+ izin), riwayat.
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
  int _tab = 0;

  static const _titles = ['QR Saya', 'Kegiatan', 'Riwayat'];

  Future<void> _logout() async {
    await _api.logout();
    await Session.clear();
    final server = await ServerConfig.load();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute<void>(builder: (_) => server == null ? const ConnectScreen() : LoginScreen(server: server)),
      (_) => false,
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return Scaffold(
      backgroundColor: theme.colorScheme.background,
      body: AppPage(
        title: _titles[_tab],
        actions: [ShadIconButton.ghost(icon: const Icon(LucideIcons.logOut, size: 18), onPressed: _logout)],
        child: IndexedStack(index: _tab, children: [
          MyQrTab(api: _api, name: widget.session.userName),
          MyEventsTab(api: _api),
          MyHistoryTab(api: _api),
        ]),
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        backgroundColor: theme.colorScheme.background,
        indicatorColor: theme.colorScheme.secondary,
        onDestinationSelected: (i) => setState(() => _tab = i),
        destinations: const [
          NavigationDestination(icon: Icon(LucideIcons.qrCode), label: 'QR Saya'),
          NavigationDestination(icon: Icon(LucideIcons.calendarDays), label: 'Kegiatan'),
          NavigationDestination(icon: Icon(LucideIcons.chartColumn), label: 'Riwayat'),
        ],
      ),
    );
  }
}
