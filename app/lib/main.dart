import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import 'app/deep_links.dart';
import 'app/home.dart';
import 'app/server_config.dart';
import 'app/session.dart';
import 'screens/connect_screen.dart';
import 'screens/login_screen.dart';

void main() => runApp(const KartuAnggotaApp());

class KartuAnggotaApp extends StatefulWidget {
  const KartuAnggotaApp({super.key, this.home, this.links});

  /// Untuk tes: lewati pemeriksaan sesi.
  final Widget? home;

  /// Untuk tes: sumber tautan (bawaan: tautan sistem Android). Tes tanpa [links] tidak memasang penangan.
  final Stream<Uri>? links;

  @override
  State<KartuAnggotaApp> createState() => _KartuAnggotaAppState();
}

class _KartuAnggotaAppState extends State<KartuAnggotaApp> {
  final _navigator = GlobalKey<NavigatorState>();
  DeepLinkHandler? _links;

  @override
  void initState() {
    super.initState();
    // Di tes widget (home diberikan) plugin tidak tersedia; tautan hanya dipasang bila diminta.
    if (widget.home == null || widget.links != null) {
      _links = DeepLinkHandler(_navigator, links: widget.links)..start();
    }
  }

  @override
  void dispose() {
    _links?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // Skema warna neutral = sama dengan dashboard (shadcn preset b0).
    return ShadApp.custom(
      theme: appTheme(Brightness.light, const ShadNeutralColorScheme.light()),
      darkTheme: appTheme(Brightness.dark, const ShadNeutralColorScheme.dark()),
      appBuilder: (context) => MaterialApp(
        navigatorKey: _navigator,
        title: 'Kartu Anggota',
        debugShowCheckedModeBanner: false,
        // Warna teks dasar Material dari tema shadcn yang aktif; tanpa ini teks biasa di mode
        // gelap ikut berwarna gelap (lihat test/dark_mode_test.dart).
        theme: () {
          final base = Theme.of(context);
          final fg = ShadTheme.of(context).colorScheme.foreground;
          return base.copyWith(
            textTheme: base.textTheme.apply(bodyColor: fg, displayColor: fg),
          );
        }(),
        home: widget.home ?? const _StartGate(),
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
    textTheme: ShadTextTheme(h1Large: fg, h1: fg, h2: fg, h3: fg, h4: fg, p: fg, blockquote: fg, table: fg, list: fg, large: fg, small: fg, lead: muted, muted: muted),
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
      if (snap.connectionState != ConnectionState.done) return const BrandSplash();
      final (session, server) = snap.data!;
      if (session != null) return homeFor(session);
      if (server != null) return LoginScreen(server: server);
      return const ConnectScreen();
    },
  );
}

/// Layar pembuka saat memuat sesi: sama dengan splash sistem (latar indigo + logo 3D),
/// sehingga peralihan dari splash Android ke app tidak berkedip.
class BrandSplash extends StatelessWidget {
  const BrandSplash({super.key});

  static const indigo = Color(0xFF4F46E5);

  @override
  Widget build(BuildContext context) => const Scaffold(
    backgroundColor: indigo,
    body: Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Image(image: AssetImage('assets/brand/logo-3d.png'), width: 144, height: 144, semanticLabel: 'Logo Kartu Anggota'),
          SizedBox(height: 16),
          Text('Kartu Anggota', style: TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w600)),
        ],
      ),
    ),
  );
}
