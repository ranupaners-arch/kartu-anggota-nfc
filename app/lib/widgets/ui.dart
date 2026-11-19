import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

/// Kerangka halaman dengan gaya shadcn (latar, judul, aksi).
class AppPage extends StatelessWidget {
  const AppPage({super.key, required this.title, required this.child, this.actions = const [], this.leading, this.maxContentWidth = 720});

  final String title;
  final Widget child;
  final List<Widget> actions;
  final Widget? leading;

  /// Lebar maksimum konten; di tablet konten diletakkan di tengah agar tidak membentang.
  final double maxContentWidth;

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return Scaffold(
      backgroundColor: theme.colorScheme.background,
      appBar: AppBar(
        backgroundColor: theme.colorScheme.background,
        foregroundColor: theme.colorScheme.foreground, // ikon kembali & aksi ikut mode gelap
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        leading: leading,
        titleSpacing: leading == null ? 16 : 0,
        title: Text(title, style: theme.textTheme.large),
        actions: [...actions, const SizedBox(width: 8)],
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
  const FlexButton({super.key, required this.label, this.onPressed, this.icon, this.variant = ShadButtonVariant.primary, this.enabled = true, this.small = false});

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final ShadButtonVariant variant;
  final bool enabled;
  final bool small;

  @override
  Widget build(BuildContext context) => ShadButton.raw(
    variant: variant,
    size: small ? ShadButtonSize.sm : ShadButtonSize.regular,
    enabled: enabled,
    onPressed: onPressed,
    expands: true,
    height: 0, // tinggi mengikuti isi
    padding: EdgeInsets.symmetric(horizontal: small ? 12 : 16, vertical: small ? 6 : 10),
    leading: icon == null ? null : Icon(icon, size: 16),
    child: Text(label, textAlign: TextAlign.center, softWrap: true),
  );
}
