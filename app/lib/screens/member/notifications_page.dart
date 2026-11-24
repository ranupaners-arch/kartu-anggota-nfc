import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../api/api_client.dart';
import '../../app/format.dart';
import '../../widgets/ui.dart';

/// Penanda "sudah dibaca" kotak notifikasi: waktu terakhir kotak dibuka, disimpan di HP.
abstract final class NoticeSeen {
  static const _key = 'notif_seen_at';

  static Future<DateTime?> load() async {
    final ms = (await SharedPreferences.getInstance()).getInt(_key);
    return ms == null ? null : DateTime.fromMillisecondsSinceEpoch(ms);
  }

  static Future<void> save(DateTime at) async => (await SharedPreferences.getInstance()).setInt(_key, at.millisecondsSinceEpoch);

  /// Notifikasi yang lebih baru dari [seenAt] (semua bila belum pernah dibuka).
  static int unread(List<MemberNotice> notices, DateTime? seenAt) => notices.where((n) => seenAt == null || n.at.isAfter(seenAt)).length;
}

/// Ikon lonceng dengan angka belum dibaca (untuk bilah atas).
class NoticeBell extends StatelessWidget {
  const NoticeBell({super.key, required this.unread, required this.onPressed});

  final int unread;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return Semantics(
      container: true, // simpul sendiri, tidak melebur ke judul bilah atas
      button: true,
      label: unread == 0 ? 'Notifikasi' : 'Notifikasi, $unread belum dibaca',
      excludeSemantics: true,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          ShadIconButton.ghost(icon: const Icon(LucideIcons.bell, size: 18), onPressed: onPressed),
          if (unread > 0)
            Positioned(
              right: 4,
              top: 4,
              child: IgnorePointer(
                child: Container(
                  constraints: const BoxConstraints(minWidth: 16),
                  height: 16,
                  padding: const EdgeInsets.symmetric(horizontal: 4),
                  alignment: Alignment.center,
                  decoration: BoxDecoration(color: theme.colorScheme.destructive, borderRadius: BorderRadius.circular(8)),
                  child: Text(
                    unread > 9 ? '9+' : '$unread',
                    style: TextStyle(color: theme.colorScheme.destructiveForeground, fontSize: 10, fontWeight: FontWeight.w700, height: 1),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// Kotak notifikasi: daftar terbaru dulu, yang belum dibaca diberi titik. Ketuk → halaman terkait.
class NotificationsPage extends StatefulWidget {
  const NotificationsPage({super.key, required this.api, this.seenBefore, this.onOpen});

  final ApiClient api;

  /// Waktu kotak terakhir dibuka sebelum kunjungan ini (penentu "belum dibaca").
  final DateTime? seenBefore;

  /// Buka halaman terkait notifikasi (izin, kartu, denda, pengumuman).
  final void Function(BuildContext context, MemberNotice notice)? onOpen;

  @override
  State<NotificationsPage> createState() => _NotificationsPageState();
}

class _NotificationsPageState extends State<NotificationsPage> {
  late Future<List<MemberNotice>> _notices = widget.api.myNotifications();

  static const _icons = {'announcement': LucideIcons.megaphone, 'excuse': LucideIcons.calendarCheck, 'card': LucideIcons.creditCard, 'fine': LucideIcons.wallet, 'reminder': LucideIcons.bellRing, 'profile': LucideIcons.userPen};

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return FutureBuilder<List<MemberNotice>>(
      future: _notices,
      builder: (context, snap) {
        final list = snap.data ?? const <MemberNotice>[];
        return RefreshIndicator(
          onRefresh: () async {
            setState(() { _notices = widget.api.myNotifications(); });
            await _notices.catchError((_) => <MemberNotice>[]);
          },
          child: ListView(
            padding: const EdgeInsets.all(16),
            physics: const AlwaysScrollableScrollPhysics(),
            children: [
              if (snap.connectionState != ConnectionState.done)
                const Padding(
                  padding: EdgeInsets.all(32),
                  child: Center(child: CircularProgressIndicator()),
                )
              else if (snap.hasError)
                ErrorBanner(snap.error is ApiException ? (snap.error as ApiException).message : 'Tidak bisa memuat notifikasi. Tarik untuk mencoba lagi.')
              else if (list.isEmpty)
                const ShadAlert(icon: Icon(LucideIcons.bellOff), title: Text('Belum ada notifikasi'), description: Text('Pengumuman, keputusan izin, dan pengingat kegiatan akan muncul di sini.'))
              else
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
                        for (var i = 0; i < list.length; i++) ...[
                          if (i > 0) Divider(height: 1, indent: 64, color: theme.colorScheme.border),
                          _tile(context, list[i], unread: widget.seenBefore == null || list[i].at.isAfter(widget.seenBefore!)),
                        ],
                      ],
                    ),
                  ),
                ),
            ],
          ),
        );
      },
    );
  }

  Widget _tile(BuildContext context, MemberNotice n, {required bool unread}) {
    final theme = ShadTheme.of(context);
    return InkWell(
      onTap: widget.onOpen == null ? null : () => widget.onOpen!(context, n),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 36,
              height: 36,
              decoration: BoxDecoration(color: theme.colorScheme.muted, borderRadius: BorderRadius.circular(10)),
              child: Icon(_icons[n.type] ?? LucideIcons.bell, size: 18, color: theme.colorScheme.foreground),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    n.title,
                    style: theme.textTheme.small.copyWith(fontWeight: unread ? FontWeight.w700 : FontWeight.w500),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                  if (n.body != null && n.body!.isNotEmpty) Text(n.body!, style: theme.textTheme.muted, maxLines: 2, overflow: TextOverflow.ellipsis),
                  const SizedBox(height: 2),
                  Text(fmtAgo(n.at), style: theme.textTheme.muted.copyWith(fontSize: 12)),
                ],
              ),
            ),
            const SizedBox(width: 8),
            // Titik penanda belum dibaca (selalu ada ruangnya agar teks tetap sejajar).
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Container(
                width: 8,
                height: 8,
                decoration: BoxDecoration(shape: BoxShape.circle, color: unread ? theme.colorScheme.destructive : Colors.transparent),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
