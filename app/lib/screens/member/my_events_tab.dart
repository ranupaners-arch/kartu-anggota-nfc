import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/format.dart';
import '../../widgets/ui.dart';
import 'excuse_screen.dart';

/// Kegiatan yang diikuti anggota + status hadir/izin + tombol ajukan izin.
class MyEventsTab extends StatefulWidget {
  const MyEventsTab({super.key, required this.api});

  final ApiClient api;

  @override
  State<MyEventsTab> createState() => _MyEventsTabState();
}

class _MyEventsTabState extends State<MyEventsTab> {
  late Future<List<MemberEvent>> _events = widget.api.myEvents();

  Future<void> _refresh() async {
    setState(() => _events = widget.api.myEvents());
    await _events.catchError((_) => <MemberEvent>[]);
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return RefreshIndicator(
      onRefresh: _refresh,
      child: FutureBuilder<List<MemberEvent>>(
        future: _events,
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
            children.add(ErrorBanner(snap.error is ApiException ? (snap.error as ApiException).message : 'Tidak bisa memuat kegiatan. Tarik untuk mencoba lagi.'));
          } else if (snap.data!.isEmpty) {
            children.add(const ShadCard(title: Text('Belum ada kegiatan'), description: Text('Kegiatan yang Anda ikuti akan muncul di sini.')));
          } else {
            for (final e in snap.data!) {
              children.add(
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: ShadCard(
                    title: Text(e.title),
                    description: Text([fmtDateTime(e.startsAt), if (e.location != null) e.location!].join(' · ')),
                    child: Padding(
                      padding: const EdgeInsets.only(top: 10),
                      child: Row(
                        children: [
                          ShadBadge.secondary(child: Text(MyEventsTabStatus.of(e))),
                          if (e.excuseNote != null) ...[
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(e.excuseNote!, style: theme.textTheme.muted, overflow: TextOverflow.ellipsis),
                            ),
                          ],
                          const Spacer(),
                          if (e.canRequestExcuse)
                            ShadButton.outline(
                              size: ShadButtonSize.sm,
                              onPressed: () async {
                                final sent = await Navigator.of(context).push<bool>(
                                  MaterialPageRoute(
                                    builder: (_) => ExcuseScreen(api: widget.api, event: e),
                                  ),
                                );
                                if (sent == true) _refresh();
                              },
                              child: Text(e.excuseStatus == 'pending' ? 'Ubah izin' : 'Ajukan izin'),
                            ),
                        ],
                      ),
                    ),
                  ),
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

/// Label status kegiatan untuk anggota: hasil absensi, status izin, atau belum.
abstract final class MyEventsTabStatus {
  static String of(MemberEvent e) {
    if (e.sessions > 1 && (e.myStatus != null || e.presentSessions > 0)) return 'Hadir ${e.presentSessions}/${e.sessions} sesi';
    if (e.myStatus != null) return statusLabel[e.myStatus] ?? e.myStatus!;
    if (e.excuseStatus != null) return '${e.excuseType == 'sakit' ? 'Sakit' : 'Izin'} ${statusLabel[e.excuseStatus]!.toLowerCase()}';
    if (e.closed) return 'Ditutup';
    return e.startsAt.isAfter(DateTime.now()) ? 'Akan datang' : 'Berlangsung';
  }
}
