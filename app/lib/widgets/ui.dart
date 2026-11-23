import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

/// Kerangka halaman dengan gaya shadcn (latar, judul, aksi).
class AppPage extends StatelessWidget {
  const AppPage({super.key, required this.title, required this.child, this.actions = const [], this.leading, this.maxContentWidth = 720, this.subtitle, this.brand = false});

  final String title;

  /// Baris kecil di bawah judul (mis. nama organisasi).
  final String? subtitle;

  /// Tampilkan logo di kiri judul (layar utama anggota, sama dengan portal web).
  final bool brand;
  final Widget child;
  final List<Widget> actions;
  final Widget? leading;

  /// Lebar maksimum konten; di tablet konten diletakkan di tengah agar tidak membentang.
  final double maxContentWidth;

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    final back = leading ?? (ModalRoute.of(context)?.impliesAppBarDismissal == true ? const BackButton() : null);
    return Scaffold(
      backgroundColor: theme.colorScheme.background,
      appBar: AppBar(
        backgroundColor: theme.colorScheme.background,
        foregroundColor: theme.colorScheme.foreground, // ikon kembali & aksi ikut mode gelap
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        automaticallyImplyLeading: false,
        titleSpacing: 0,
        // Isi bilah atas selebar konten & sejajar tepinya (rapi di tablet/laptop, bukan menempel di tepi layar).
        title: Align(
          alignment: Alignment.center,
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: maxContentWidth),
            child: Padding(
              padding: EdgeInsets.only(left: back == null ? 16 : 4, right: actions.isEmpty ? 16 : 4),
              child: Row(
                children: [
                  if (back != null) ...[back, const SizedBox(width: 4)],
                  if (brand) ...[ClipRRect(borderRadius: BorderRadius.circular(8), child: Image.asset('assets/brand/logo-2d.webp', width: 32, height: 32)), const SizedBox(width: 10)],
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(title, style: theme.textTheme.large, maxLines: 1, overflow: TextOverflow.ellipsis),
                        if (subtitle != null) Text(subtitle!, style: theme.textTheme.muted.copyWith(fontSize: 12), maxLines: 1, overflow: TextOverflow.ellipsis),
                      ],
                    ),
                  ),
                  ...actions,
                ],
              ),
            ),
          ),
        ),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(1),
          child: Divider(height: 1, color: theme.colorScheme.border),
        ),
      ),
      body: SafeArea(
        child: Align(
          alignment: Alignment.topCenter,
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: maxContentWidth),
            child: child,
          ),
        ),
      ),
    );
  }
}

/// Pesan error singkat (mis. jaringan) dengan gaya shadcn.
class ErrorBanner extends StatelessWidget {
  const ErrorBanner(this.message, {super.key});

  final String message;

  @override
  Widget build(BuildContext context) => ShadAlert.destructive(icon: const Icon(LucideIcons.circleAlert), title: const Text('Gagal'), description: Text(message));
}

/// Tombol yang mengisi lebar ruangnya, dengan label yang boleh turun baris: aman di layar sempit
/// dan pada ukuran huruf besar (tinggi mengikuti isi). Hanya untuk ruang yang lebarnya terbatas
/// (mis. Column dengan stretch atau di dalam Expanded).
class FlexButton extends StatelessWidget {
  const FlexButton({
    super.key,
    required this.label,
    this.onPressed,
    this.icon,
    this.variant = ShadButtonVariant.primary,
    this.enabled = true,
    this.small = false,
    this.backgroundColor,
    this.foregroundColor,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final ShadButtonVariant variant;
  final bool enabled;
  final bool small;

  /// Warna khusus (mis. tombol putih di layar sambutan hitam).
  final Color? backgroundColor, foregroundColor;

  @override
  Widget build(BuildContext context) => ShadButton.raw(
    variant: variant,
    size: small ? ShadButtonSize.sm : ShadButtonSize.regular,
    enabled: enabled,
    onPressed: onPressed,
    expands: true,
    backgroundColor: backgroundColor,
    hoverBackgroundColor: backgroundColor?.withValues(alpha: 0.9),
    pressedBackgroundColor: backgroundColor?.withValues(alpha: 0.8),
    foregroundColor: foregroundColor,
    hoverForegroundColor: foregroundColor,
    pressedForegroundColor: foregroundColor,
    height: 0, // tinggi mengikuti isi
    padding: EdgeInsets.symmetric(horizontal: small ? 12 : 16, vertical: small ? 6 : 10),
    // Ikon & tulisan dipusatkan bersama (bukan ikon di tepi kiri), sama dengan tombol portal web.
    child: Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        if (icon != null) ...[Icon(icon, size: 16), const SizedBox(width: 8)],
        Flexible(child: Text(label, textAlign: TextAlign.center, softWrap: true)),
      ],
    ),
  );
}

/// Lencana status kehadiran/izin berwarna (sama dengan portal web): hijau hadir, kuning telat,
/// merah alpa/ditolak, biru izin/sakit, abu lainnya. Teks tetap ada — warna bukan satu-satunya penanda.
class StatusBadge extends StatelessWidget {
  const StatusBadge({super.key, required this.status, required this.label});

  final String? status;
  final String label;

  @override
  Widget build(BuildContext context) {
    final dark = ShadTheme.of(context).brightness == Brightness.dark;
    final (Color bg, Color fg) = switch (status) {
      'hadir' || 'approved' || 'paid' => dark ? (const Color(0xFF052E16), const Color(0xFF4ADE80)) : (const Color(0xFFDCFCE7), const Color(0xFF166534)),
      'telat' || 'pending' => dark ? (const Color(0xFF422006), const Color(0xFFFACC15)) : (const Color(0xFFFEF9C3), const Color(0xFF854D0E)),
      'alpa' || 'rejected' || 'unpaid' => dark ? (const Color(0xFF450A0A), const Color(0xFFF87171)) : (const Color(0xFFFEE2E2), const Color(0xFF991B1B)),
      'izin' || 'sakit' => dark ? (const Color(0xFF172554), const Color(0xFF93C5FD)) : (const Color(0xFFDBEAFE), const Color(0xFF1E40AF)),
      _ => dark ? (const Color(0xFF27272A), const Color(0xFFD4D4D8)) : (const Color(0xFFF4F4F5), const Color(0xFF3F3F46)),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(6)),
      child: Text(
        label,
        style: TextStyle(color: fg, fontSize: 12, fontWeight: FontWeight.w500),
      ),
    );
  }
}

/// Batas lebar: di bawahnya satu kolom (HP), di atasnya dua kolom (tablet/laptop).
const kTwoColumnWidth = 760.0;

/// Lebar konten tab utama di layar lebar.
const kWideContentWidth = 1120.0;

/// Dua kolom di layar lebar, satu kolom (kiri lalu kanan) di HP. Kolom rata atas, jarak seragam.
class ResponsiveColumns extends StatelessWidget {
  const ResponsiveColumns({super.key, required this.left, required this.right, this.leftFlex = 1, this.rightFlex = 1, this.gap = 24});

  final List<Widget> left, right;
  final int leftFlex, rightFlex;
  final double gap;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, box) {
      if (box.maxWidth < kTwoColumnWidth || right.isEmpty) {
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [...left, ...right]);
      }
      return Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            flex: leftFlex,
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: left),
          ),
          SizedBox(width: gap),
          Expanded(
            flex: rightFlex,
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: right),
          ),
        ],
      );
    },
  );
}

/// Grid kartu sama lebar & sama tinggi per baris: 1 kolom di HP, 2–3 kolom di layar lebar.
class ResponsiveGrid extends StatelessWidget {
  const ResponsiveGrid({super.key, required this.children, this.minItemWidth = 340, this.spacing = 12});

  final List<Widget> children;
  final double minItemWidth, spacing;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, box) {
      final cols = ((box.maxWidth + spacing) / (minItemWidth + spacing)).floor().clamp(1, 3);
      if (cols == 1) {
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, spacing: spacing, children: children);
      }
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        spacing: spacing,
        children: [
          for (var i = 0; i < children.length; i += cols)
            IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                spacing: spacing,
                children: [for (var k = i; k < i + cols; k++) Expanded(child: k < children.length ? children[k] : const SizedBox.shrink())],
              ),
            ),
        ],
      );
    },
  );
}

/// Kartu bagian seragam (padding 16, judul 16 tebal, deskripsi redup) — sama dengan kartu portal web.
class SectionCard extends StatelessWidget {
  const SectionCard({super.key, this.title, this.icon, this.description, this.trailing, required this.child, this.onTap});

  final String? title;
  final IconData? icon;
  final String? description;
  final Widget? trailing;
  final Widget child;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    final radius = BorderRadius.circular(14);
    final body = Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (title != null)
            // Judul kiri, aksi (mis. "Lihat semua") kanan; di layar sempit/huruf besar aksi turun baris.
            Wrap(
              alignment: WrapAlignment.spaceBetween,
              crossAxisAlignment: WrapCrossAlignment.center,
              runSpacing: 4,
              children: [
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (icon != null) ...[Icon(icon, size: 18, color: theme.colorScheme.foreground), const SizedBox(width: 8)],
                    Flexible(
                      child: Text(title!, style: theme.textTheme.p.copyWith(fontWeight: FontWeight.w600, fontSize: 16)),
                    ),
                  ],
                ),
                ?trailing,
              ],
            ),
          if (description != null)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(description!, style: theme.textTheme.muted),
            ),
          if (title != null || description != null) const SizedBox(height: 12),
          child,
        ],
      ),
    );
    return Material(
      color: theme.colorScheme.card,
      shape: RoundedRectangleBorder(
        borderRadius: radius,
        side: BorderSide(color: theme.colorScheme.border),
      ),
      clipBehavior: Clip.antiAlias,
      child: onTap == null ? body : InkWell(onTap: onTap, child: body),
    );
  }
}
