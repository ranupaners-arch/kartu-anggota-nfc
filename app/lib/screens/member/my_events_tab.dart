import 'package:flutter/services.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/format.dart';
import '../../widgets/ui.dart';
import 'event_detail_page.dart';
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
    setState(() {
      _events = widget.api.myEvents();
    });
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
            padding: const EdgeInsets.all(16),
            title: Row(
              children: [
                if (a.pinned) ...[const Icon(LucideIcons.pin, size: 16), const SizedBox(width: 6)],
                Expanded(
                  child: Text(a.title, style: theme.textTheme.p.copyWith(fontWeight: FontWeight.w600, fontSize: 16)),
                ),
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
            if (snap.data!.isEmpty && children.isEmpty) {
              children.add(const ShadCard(title: Text('Belum ada kegiatan'), description: Text('Kegiatan yang Anda ikuti akan muncul di sini.')));
            }
            final cards = <Widget>[];
            for (final e in snap.data!) {
              cards.add(
                // Ketuk kartu → halaman detail kegiatan (jadwal, check-in, izin).
                GestureDetector(
                  behavior: HitTestBehavior.opaque,
                  onTap: () async {
                    await Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (_) => Scaffold(
                          body: AppPage(
                            title: 'Detail kegiatan',
                            maxContentWidth: 640,
                            child: EventDetailPage(api: widget.api, event: e),
                          ),
                        ),
                      ),
                    );
                    _refresh();
                  },
                  child: ShadCard(
                    padding: const EdgeInsets.all(16),
                    title: Text(e.title, style: theme.textTheme.p.copyWith(fontWeight: FontWeight.w600, fontSize: 16)),
                    description: Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: Wrap(
                        spacing: 12,
                        runSpacing: 2,
                        children: [
                          Row(mainAxisSize: MainAxisSize.min, spacing: 4, children: [const Icon(LucideIcons.calendarDays, size: 14), Text(fmtDateTime(e.startsAt))]),
                          if (e.location != null)
                            Row(
                              mainAxisSize: MainAxisSize.min,
                              spacing: 4,
                              children: [
                                const Icon(LucideIcons.mapPin, size: 14),
                                Flexible(child: Text(e.location!, overflow: TextOverflow.ellipsis)),
                              ],
                            ),
                        ],
                      ),
                    ),
                    child: Padding(
                      padding: const EdgeInsets.only(top: 10),
                      child: Row(
                        children: [
                          // Lencana & catatan boleh turun baris; tombol izin tetap rata kanan.
                          Expanded(
                            child: Wrap(
                              spacing: 8,
                              runSpacing: 4,
                              crossAxisAlignment: WrapCrossAlignment.center,
                              children: [
                                StatusBadge(status: e.myStatus ?? e.excuseStatus, label: MyEventsTabStatus.of(e)),
                                if (e.excuseNote != null) Text(e.excuseNote!, style: theme.textTheme.muted, maxLines: 2, overflow: TextOverflow.ellipsis),
                              ],
                            ),
                          ),
                          const SizedBox(width: 8),
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
            // Kartu kegiatan: 1 kolom di HP, 2–3 kolom sama tinggi di tablet/laptop.
            children.add(ResponsiveGrid(minItemWidth: 400, children: cards));
            children.add(const SizedBox(height: 12));
          }
          if (snap.connectionState == ConnectionState.done && !snap.hasError) {
            children.add(
              Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 420),
                  child: FlexButton(
                    label: 'Langganan jadwal ke kalender',
                    icon: LucideIcons.calendarPlus,
                    variant: ShadButtonVariant.outline,
                    onPressed: () => showCalendarSubscription(context, widget.api),
                  ),
                ),
              ),
            );
          }
          return ListView(padding: const EdgeInsets.all(16), physics: const AlwaysScrollableScrollPhysics(), children: children);
        },
      ),
    );
  }
}

/// Label status kegiatan untuk anggota: hasil absensi, status izin, atau belum.
/// Dialog tautan langganan jadwal (.ics): salin, lalu tambahkan di Google Calendar / kalender HP.
Future<void> showCalendarSubscription(BuildContext context, ApiClient api) {
  return showShadDialog<void>(
    context: context,
    builder: (context) => _CalendarDialog(api: api),
  );
}

class _CalendarDialog extends StatefulWidget {
  const _CalendarDialog({required this.api});

  final ApiClient api;

  @override
  State<_CalendarDialog> createState() => _CalendarDialogState();
}

class _CalendarDialogState extends State<_CalendarDialog> {
  late Future<String> _url = widget.api.calendarUrl();
  bool _copied = false;

  Future<void> _copy(String url) async {
    await Clipboard.setData(ClipboardData(text: url));
    if (mounted) setState(() => _copied = true);
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return FutureBuilder<String>(
      future: _url,
      builder: (context, snap) {
        final url = snap.data;
        return ShadDialog(
          title: const Text('Langganan jadwal'),
          description: const Text('Kegiatan Anda muncul otomatis di aplikasi kalender dan ikut diperbarui.'),
          actions: [
            ShadButton.outline(onPressed: () => Navigator.of(context).pop(), child: const Text('Tutup')),
            ShadButton(
              enabled: url != null,
              onPressed: url == null ? null : () => _copy(url),
              leading: Icon(_copied ? LucideIcons.check : LucideIcons.copy, size: 16),
              child: Text(_copied ? 'Tersalin' : 'Salin tautan'),
            ),
          ],
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (snap.hasError)
                  ErrorBanner(snap.error is ApiException ? (snap.error as ApiException).message : 'Tidak bisa memuat tautan. Coba lagi nanti.')
                else if (url == null)
                  const Center(
                    child: Padding(padding: EdgeInsets.all(12), child: CircularProgressIndicator()),
                  )
                else ...[
                  SelectableText(url, style: theme.textTheme.small.copyWith(fontFamily: 'monospace')),
                  const SizedBox(height: 12),
                  Text('Cara memakai:', style: theme.textTheme.small),
                  const SizedBox(height: 4),
                  Text(
                    '1. Salin tautan.\n'
                    '2. Google Calendar: buka calendar.google.com di browser → Setelan → Tambahkan kalender → Dari URL → tempel.\n'
                    '3. Kalender iPhone: Setelan → Kalender → Akun → Tambah akun → Lainnya → Tambah kalender langganan.',
                    style: theme.textTheme.muted,
                  ),
                  const SizedBox(height: 8),
                  Text('Rahasiakan tautan ini. Bila tersebar, buat tautan baru — yang lama langsung tidak berlaku.', style: theme.textTheme.muted),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: ShadButton.link(
                      padding: EdgeInsets.zero,
                      onPressed: () => setState(() {
                        _copied = false;
                        _url = widget.api.calendarUrl(reset: true);
                      }),
                      child: const Text('Buat tautan baru'),
                    ),
                  ),
                ],
              ],
            ),
          ),
        );
      },
    );
  }
}

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
