import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../api/api_client.dart';

typedef BadgeInfo = ({String key, String title, String description, bool earned, int progress, int target, String? hint, bool percent});

/// "3/10" atau "85%/90%" (lencana berbasis persentase).
String badgeProgress(BadgeInfo b) => b.percent ? '${b.progress}%/${b.target}%' : '${b.progress}/${b.target}';

const _icons = {
  'first': LucideIcons.footprints,
  'on_time_5': LucideIcons.alarmClockCheck,
  'streak_10': LucideIcons.flame,
  'diligent': LucideIcons.trophy,
  'no_alpa': LucideIcons.shieldCheck,
  'card_loyal': LucideIcons.creditCard,
};

Color _accent(BuildContext context) => ShadTheme.of(context).brightness == Brightness.dark ? const Color(0xFFA5B4FC) : const Color(0xFF4F46E5);

/// Ringkasan poin & lencana + kisi lencana (diraih berwarna, belum diraih redup dengan progres).
/// Ketuk lencana untuk melihat syarat & progresnya.
class BadgesView extends StatelessWidget {
  const BadgesView({super.key, required this.data});

  final Achievements data;

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Wrap(
          alignment: WrapAlignment.spaceBetween,
          crossAxisAlignment: WrapCrossAlignment.end,
          spacing: 8,
          children: [
            Text.rich(
              TextSpan(
                children: [
                  TextSpan(text: '${data.points}', style: theme.textTheme.h3),
                  TextSpan(text: '  poin', style: theme.textTheme.muted),
                ],
              ),
            ),
            Text('${data.earned}/${data.badges.length} lencana', style: theme.textTheme.muted),
          ],
        ),
        const SizedBox(height: 12),
        LayoutBuilder(
          builder: (context, box) {
            // 3 kolom di HP, 6 di layar lebar; ukuran seragam.
            final cols = box.maxWidth >= 520 ? 6 : 3;
            final w = (box.maxWidth - (cols - 1) * 8) / cols;
            return Wrap(
              spacing: 8,
              runSpacing: 12,
              children: [for (final b in data.badges) SizedBox(width: w, child: _BadgeTile(b))],
            );
          },
        ),
      ],
    );
  }
}

class _BadgeTile extends StatelessWidget {
  const _BadgeTile(this.b);

  final BadgeInfo b;

  void _detail(BuildContext context) => showModalBottomSheet<void>(
    context: context,
    showDragHandle: true,
    builder: (sheet) {
      final theme = ShadTheme.of(sheet);
      return SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              _BadgeIcon(b, size: 64),
              const SizedBox(height: 12),
              Text(b.title, style: theme.textTheme.h4, textAlign: TextAlign.center),
              const SizedBox(height: 4),
              Text(b.description, style: theme.textTheme.muted, textAlign: TextAlign.center),
              const SizedBox(height: 16),
              if (b.earned)
                Text('Sudah diraih 🎉', style: theme.textTheme.small.copyWith(fontWeight: FontWeight.w600))
              else ...[
                SizedBox(
                  width: 220,
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(4),
                    child: LinearProgressIndicator(value: b.progress / b.target, minHeight: 8, backgroundColor: theme.colorScheme.muted, color: _accent(sheet)),
                  ),
                ),
                const SizedBox(height: 6),
                Text(badgeProgress(b), style: theme.textTheme.muted),
              ],
              if (b.hint != null) ...[const SizedBox(height: 8), Text(b.hint!, style: theme.textTheme.muted.copyWith(fontSize: 12), textAlign: TextAlign.center)],
            ],
          ),
        ),
      );
    },
  );

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return Semantics(
      button: true,
      label: '${b.title}, ${b.earned ? 'sudah diraih' : 'belum, ${badgeProgress(b)}'}',
      excludeSemantics: true,
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => _detail(context),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Column(
            children: [
              _BadgeIcon(b),
              const SizedBox(height: 6),
              Text(
                b.title,
                style: theme.textTheme.small.copyWith(fontSize: 12, fontWeight: b.earned ? FontWeight.w600 : FontWeight.w400),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                textAlign: TextAlign.center,
              ),
              Text(b.earned ? 'Diraih' : badgeProgress(b), style: theme.textTheme.muted.copyWith(fontSize: 11)),
            ],
          ),
        ),
      ),
    );
  }
}

/// Lingkaran ikon lencana: diraih = indigo penuh; belum = redup bergaris tepi.
class _BadgeIcon extends StatelessWidget {
  const _BadgeIcon(this.b, {this.size = 48});

  final BadgeInfo b;
  final double size;

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    final accent = _accent(context);
    final dark = theme.brightness == Brightness.dark;
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: b.earned ? accent : theme.colorScheme.muted,
        border: b.earned ? null : Border.all(color: theme.colorScheme.border),
      ),
      child: Icon(_icons[b.key] ?? LucideIcons.award, size: size * 0.46, color: b.earned ? (dark ? const Color(0xFF1E1B4B) : Colors.white) : theme.colorScheme.mutedForeground),
    );
  }
}
