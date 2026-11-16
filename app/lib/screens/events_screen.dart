import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../api/api_client.dart';
import '../app/session.dart';
import '../widgets/ui.dart';
import 'login_screen.dart';
import 'nfc_tools_screen.dart';
import 'scan_screen.dart';

class EventsScreen extends StatefulWidget {
  const EventsScreen({super.key, required this.session});

  final Session session;

  @override
  State<EventsScreen> createState() => _EventsScreenState();
}

class _EventsScreenState extends State<EventsScreen> {
  late final ApiClient _api = widget.session.client();
  late Future<List<EventSummary>> _events = _load();

  Future<List<EventSummary>> _load() async {
    try {
      return await _api.ongoingEvents();
    } on ApiException catch (e) {
      if (e.status == 401 && mounted) await _logout(expired: true);
      rethrow;
    }
  }

  Future<void> _refresh() async {
    setState(() => _events = _load());
    await _events.catchError((_) => <EventSummary>[]);
  }

  Future<void> _logout({bool expired = false}) async {
    if (!expired) await _api.logout();
    await Session.clear();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(MaterialPageRoute<void>(builder: (_) => const LoginScreen()), (_) => false);
  }

  String _hhmm(DateTime t) => '${t.hour.toString().padLeft(2, '0')}.${t.minute.toString().padLeft(2, '0')}';

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return AppPage(
      title: 'Kegiatan Berlangsung',
      actions: [
        ShadIconButton.ghost(icon: const Icon(LucideIcons.wrench, size: 18),
            onPressed: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const NfcToolsScreen()))),
        ShadIconButton.ghost(icon: const Icon(LucideIcons.logOut, size: 18), onPressed: _logout),
      ],
      child: RefreshIndicator(
        onRefresh: _refresh,
        child: FutureBuilder<List<EventSummary>>(
          future: _events,
          builder: (context, snap) {
            final children = <Widget>[
              Text('Masuk sebagai ${widget.session.userName}', style: theme.textTheme.muted),
              const SizedBox(height: 12),
            ];
            if (snap.connectionState != ConnectionState.done) {
              children.add(const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator())));
            } else if (snap.hasError) {
              children.add(ErrorBanner(snap.error is ApiException ? (snap.error as ApiException).message : 'Server tidak terjangkau. Tarik ke bawah untuk mencoba lagi.'));
            } else if (snap.data!.isEmpty) {
              children.add(ShadCard(
                title: const Text('Tidak ada kegiatan berlangsung'),
                description: const Text('Kegiatan muncul di sini saat jendela check-in dibuka. Tarik ke bawah untuk memuat ulang.'),
              ));
            } else {
              for (final e in snap.data!) {
                children.add(Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: GestureDetector(
                    onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => ScanScreen(session: widget.session, event: e))),
                    child: ShadCard(
                      leading: Padding(padding: const EdgeInsets.only(right: 12), child: Icon(LucideIcons.calendarDays, color: theme.colorScheme.mutedForeground)),
                      title: Text(e.title),
                      description: Text([
                        if (e.location != null) e.location!,
                        if (e.checkinClosesAt != null) 'check-in ditutup ${_hhmm(e.checkinClosesAt!)}',
                      ].join(' · ')),
                      trailing: Icon(LucideIcons.chevronRight, color: theme.colorScheme.mutedForeground),
                    ),
                  ),
                ));
              }
            }
            return ListView(padding: const EdgeInsets.all(16), physics: const AlwaysScrollableScrollPhysics(), children: children);
          },
        ),
      ),
    );
  }
}
