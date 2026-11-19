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
  // Pengumuman opsional: gagal dimuat (mis. server lama) tidak mengganggu daftar kegiatan.

  List<Announcement> _anns = const [];

  @override
  void initState() {
    super.initState();
    _loadAnnouncements();
  }

  Future<List<Announcement>> _loadAnnouncements() => widget.api.myAnnouncements().catchError((_) => <Announcement>[]).then((a) {
    if (mounted) setState(() => _anns = a);
    return a;
  });

  Future<void> _refresh() async {
    _loadAnnouncements();
    setState(() => _events = widget.api.myEvents());
    await _events.catchError((_) => <MemberEvent>[]);
  }

  List<Widget> _top(BuildContext context, List<MemberEvent> events, List<Announcement> anns) {
    final theme = ShadTheme.of(context);
    final now = DateTime.now();
    String when(DateTime t) => '${t.day == now.day ? 'Hari ini' : 'Besok'} pukul ${fmtTime(t)}';
    return [
      for (final e in eventReminders(events, now))
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: ShadAlert(
            icon: const Icon(LucideIcons.bellRing),
            title: Text('Pengingat: ${e.title}'),
            description: Text([when(e.startsAt), if (e.location != null) e.location!, 'bawa kartu anggota'].join(' · ')),
          ),
        ),
      for (final a in anns)
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: ShadCard(
            title: Row(
              children: [
                if (a.pinned) ...[const Icon(LucideIcons.pin, size: 16), const SizedBox(width: 6)],
                Expanded(child: Text(a.title)),
              ],
            ),
            description: Text([if (a.createdAt != null) fmtDate(a.createdAt!), if (a.division != null) 'Divisi ${a.division}'].join(' · ')),
            child: Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(a.body, style: theme.textTheme.p),
            ),
          ),
        ),
    ];
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
          } else {
            // Pengingat & pengumuman di atas, lalu kegiatan.
            children.addAll(_top(context, snap.data!, _anns));
            if (children.isEmpty) {
              children.add(const ShadCard(title: Text('Belum ada kegiatan'), description: Text('Kegiatan yang Anda ikuti akan muncul di sini.')));
            }
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

/// Kegiatan yang dimulai dalam 24 jam ke depan dan belum ada status/izin: perlu diingatkan.
List<MemberEvent> eventReminders(List<MemberEvent> events, DateTime now) => [
  for (final e in events)
    if (!e.closed && e.myStatus == null && e.excuseStatus == null && e.startsAt.isAfter(now) && e.startsAt.difference(now) <= const Duration(hours: 24)) e,
];
