import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

/// Kerangka halaman dengan gaya shadcn (latar, judul, aksi).
class AppPage extends StatelessWidget {
  const AppPage({super.key, required this.title, required this.child, this.actions = const [], this.leading});

  final String title;
  final Widget child;
  final List<Widget> actions;
  final Widget? leading;

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return Scaffold(
      backgroundColor: theme.colorScheme.background,
      appBar: AppBar(
        backgroundColor: theme.colorScheme.background,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        leading: leading,
        titleSpacing: leading == null ? 16 : 0,
        title: Text(title, style: theme.textTheme.large),
        actions: [...actions, const SizedBox(width: 8)],
        bottom: PreferredSize(preferredSize: const Size.fromHeight(1), child: Divider(height: 1, color: theme.colorScheme.border)),
      ),
      body: SafeArea(child: child),
    );
  }
}

/// Pesan error singkat (mis. jaringan) dengan gaya shadcn.
class ErrorBanner extends StatelessWidget {
  const ErrorBanner(this.message, {super.key});

  final String message;

  @override
  Widget build(BuildContext context) => ShadAlert.destructive(
        icon: const Icon(LucideIcons.circleAlert),
        title: const Text('Gagal'),
        description: Text(message),
      );
}
