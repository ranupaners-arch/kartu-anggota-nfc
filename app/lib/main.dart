import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import 'app/session.dart';
import 'screens/events_screen.dart';
import 'screens/login_screen.dart';

void main() => runApp(const KartuAnggotaApp());

class KartuAnggotaApp extends StatelessWidget {
  const KartuAnggotaApp({super.key, this.home});

  /// Untuk tes: lewati pemeriksaan sesi.
  final Widget? home;

  @override
  Widget build(BuildContext context) {
    // Skema warna neutral = sama dengan dashboard (shadcn preset b0).
    return ShadApp.custom(
      theme: ShadThemeData(brightness: Brightness.light, colorScheme: const ShadNeutralColorScheme.light()),
      darkTheme: ShadThemeData(brightness: Brightness.dark, colorScheme: const ShadNeutralColorScheme.dark()),
      appBuilder: (context) => MaterialApp(
        title: 'Kartu Anggota',
        debugShowCheckedModeBanner: false,
        theme: Theme.of(context),
        home: home ?? const _StartGate(),
        builder: (context, child) => ShadAppBuilder(child: child!),
      ),
    );
  }
}

/// Buka daftar kegiatan bila sudah login, selain itu halaman login.
class _StartGate extends StatelessWidget {
  const _StartGate();

  @override
  Widget build(BuildContext context) => FutureBuilder<Session?>(
        future: Session.load(),
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) return const Scaffold(body: Center(child: CircularProgressIndicator()));
          final s = snap.data;
          return s == null ? const LoginScreen() : EventsScreen(session: s);
        },
      );
}
