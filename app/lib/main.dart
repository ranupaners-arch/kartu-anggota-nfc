import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import 'app/home.dart';
import 'app/server_config.dart';
import 'app/session.dart';
import 'screens/connect_screen.dart';
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
      theme: appTheme(Brightness.light, const ShadNeutralColorScheme.light()),
      darkTheme: appTheme(Brightness.dark, const ShadNeutralColorScheme.dark()),
      appBuilder: (context) => MaterialApp(
        title: 'Kartu Anggota',
        debugShowCheckedModeBanner: false,
        // Warna teks dasar Material dari tema shadcn yang aktif; tanpa ini teks biasa di mode
        // gelap ikut berwarna gelap (lihat test/dark_mode_test.dart).
        theme: () {
          final base = Theme.of(context);
          final fg = ShadTheme.of(context).colorScheme.foreground;
          return base.copyWith(textTheme: base.textTheme.apply(bodyColor: fg, displayColor: fg));
        }(),
        home: home ?? const _StartGate(),
        builder: (context, child) => ShadAppBuilder(child: child!),
      ),
    );
  }
}

/// Tema shadcn dengan warna teks mengikuti skema aktif.
///
/// Gaya teks bawaan shadcn (large, muted, small, h4, …) tidak membawa warna; komponen shadcn
/// mewarnainya sendiri, tetapi teks biasa yang memakai gaya itu mewarisi warna dari Material
/// sehingga di mode gelap menjadi gelap di atas gelap. Di sini tiap gaya diberi warna: muted/lead
/// memakai mutedForeground, sisanya foreground. Ukuran & font bawaan tetap (digabung oleh shadcn).
ShadThemeData appTheme(Brightness brightness, ShadColorScheme c) {
  final fg = TextStyle(color: c.foreground);
  final muted = TextStyle(color: c.mutedForeground);
  return ShadThemeData(
    brightness: brightness,
    colorScheme: c,
    textTheme: ShadTextTheme(
      h1Large: fg, h1: fg, h2: fg, h3: fg, h4: fg, p: fg, blockquote: fg, table: fg, list: fg,
      large: fg, small: fg, lead: muted, muted: muted,
    ),
  );
}

/// Urutan awal: sudah login → kegiatan; server diketahui → login; selain itu → hubungkan organisasi.
class _StartGate extends StatelessWidget {
  const _StartGate();

  static Future<(Session?, ServerConfig?)> _load() async => (await Session.load(), await ServerConfig.load());

  @override
  Widget build(BuildContext context) => FutureBuilder<(Session?, ServerConfig?)>(
        future: _load(),
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) return const Scaffold(body: Center(child: CircularProgressIndicator()));
          final (session, server) = snap.data!;
          if (session != null) return homeFor(session);
          if (server != null) return LoginScreen(server: server);
          return const ConnectScreen();
        },
      );
}
