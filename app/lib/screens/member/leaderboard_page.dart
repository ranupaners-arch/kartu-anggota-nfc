import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../widgets/ui.dart';

/// Papan peringkat poin: hanya anggota yang memilih tampil. Peringkat sendiri selalu terlihat oleh pemiliknya.
class LeaderboardPage extends StatefulWidget {
  const LeaderboardPage({super.key, required this.api});

  final ApiClient api;

  @override
  State<LeaderboardPage> createState() => _LeaderboardPageState();
}

class _LeaderboardPageState extends State<LeaderboardPage> {
  Leaderboard? _board;
  String? _error;
  bool _busy = true;

  @override
  void initState() {
    super.initState();
    _run(widget.api.leaderboard);
  }

  Future<void> _run(Future<Leaderboard> Function() action) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    Leaderboard? board;
    String? error;
    try {
      board = await action();
    } on ApiException catch (e) {
      error = e.status == 404 ? 'Server organisasi Anda belum mendukung papan peringkat.' : e.message;
    } catch (_) {
      error = 'Server tidak terjangkau. Tarik untuk mencoba lagi.';
    }
    if (!mounted) return;
    setState(() {
      _busy = false;
      _board = board ?? _board;
      _error = error;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    final b = _board;
    final me = b?.me;
    return RefreshIndicator(
      onRefresh: () => _run(widget.api.leaderboard),
      child: ListView(
        padding: const EdgeInsets.all(16),
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          if (_error != null) ...[ErrorBanner(_error!), const SizedBox(height: 12)],
          if (b == null && _busy)
            const Padding(
              padding: EdgeInsets.all(32),
              child: Center(child: CircularProgressIndicator()),
            ),
          if (b != null && me == null) Text('Papan peringkat tersedia untuk anggota aktif periode ini.', style: theme.textTheme.muted),
          if (b != null && me != null) ...[
            SectionCard(
              title: 'Peringkat Anda',
              icon: LucideIcons.award,
              description: 'Hanya Anda yang melihat ini.',
              child: Text('${me.rank} dari ${me.total} anggota aktif · ${me.points} poin', style: theme.textTheme.large),
            ),
            const SizedBox(height: 12),
            SectionCard(
              title: 'Yang memilih tampil',
              icon: LucideIcons.trophy,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (b.top.isEmpty) Text('Belum ada anggota yang memilih tampil.', style: theme.textTheme.muted),
                  for (final t in b.top)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 8),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        spacing: 12,
                        children: [
                          Text('${t.rank}', style: theme.textTheme.muted),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(t.isMe ? '${t.name} (Anda)' : t.name, style: theme.textTheme.small.copyWith(fontWeight: t.isMe ? FontWeight.w700 : FontWeight.w400)),
                                if (t.division != null) Text(t.division!, style: theme.textTheme.muted.copyWith(fontSize: 12)),
                              ],
                            ),
                          ),
                          Text('${t.points} poin', style: theme.textTheme.small),
                        ],
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(height: 12),
            Text('Bila Anda memilih tampil, anggota lain melihat nama, divisi, dan poin Anda. Bisa diubah kapan saja.', style: theme.textTheme.muted),
            const SizedBox(height: 8),
            FlexButton(
              label: b.optedIn ? 'Sembunyikan nama saya' : 'Tampilkan nama saya',
              icon: b.optedIn ? LucideIcons.eyeOff : LucideIcons.eye,
              variant: ShadButtonVariant.outline,
              enabled: !_busy,
              onPressed: () => _run(() => widget.api.setLeaderboard(optIn: !b.optedIn)),
            ),
          ],
        ],
      ),
    );
  }
}
