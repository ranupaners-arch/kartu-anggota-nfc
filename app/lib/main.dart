import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import 'app/deep_links.dart';
import 'app/home.dart';
import 'app/server_config.dart';
import 'app/session.dart';
import 'screens/splash_screen.dart';
import 'screens/welcome_screen.dart';
import 'widgets/ui_scale.dart';

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
          final c = ShadTheme.of(context).colorScheme;
          final fg = c.foreground;
          WidgetStateProperty<T> bySelected<T>(T selected, T other) => WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? selected : other);
          return base.copyWith(
            textTheme: base.textTheme.apply(bodyColor: fg, displayColor: fg),
            // Panel bawah ikut warna kartu; bawaan Material terang, sehingga teksnya hilang di mode gelap.
            bottomSheetTheme: BottomSheetThemeData(backgroundColor: c.card, modalBackgroundColor: c.card, surfaceTintColor: Colors.transparent, dragHandleColor: c.mutedForeground),
            // Navigasi bawah mengikuti skema shadcn: aktif = foreground, lain = mutedForeground.
            // Tanpa ini ikon & label memakai warna bawaan Material (abu gelap) yang hilang di mode gelap.
            navigationBarTheme: NavigationBarThemeData(
              backgroundColor: c.background,
              surfaceTintColor: Colors.transparent,
              indicatorColor: c.secondary,
              iconTheme: bySelected(IconThemeData(color: c.foreground), IconThemeData(color: c.mutedForeground)),
              labelTextStyle: bySelected(TextStyle(color: c.foreground, fontSize: 12, fontWeight: FontWeight.w600), TextStyle(color: c.mutedForeground, fontSize: 12)),
            ),
            // Navigasi samping di layar lebar (laptop/tablet mendatar): warna sama dengan navigasi bawah.
            navigationRailTheme: NavigationRailThemeData(
              backgroundColor: c.background,
              indicatorColor: c.secondary,
              selectedIconTheme: IconThemeData(color: c.foreground),
              unselectedIconTheme: IconThemeData(color: c.mutedForeground),
              selectedLabelTextStyle: TextStyle(color: c.foreground, fontSize: 12, fontWeight: FontWeight.w600),
              unselectedLabelTextStyle: TextStyle(color: c.mutedForeground, fontSize: 12),
            ),
          );
        }(),
        home: widget.home ?? const _StartGate(),
        builder: (context, child) => UiScale(child: ShadAppBuilder(child: child!)),
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
    // Bawaan shadcn: teks isian memakai gaya "muted" (abu) — sama dengan petunjuk, sehingga isian yang sudah
    // terisi tampak kosong. Teks isian memakai warna utama; petunjuk (placeholder) tetap abu.
    inputTheme: ShadInputTheme(style: fg, placeholderStyle: muted),
  );
}

/// Urutan awal: splash (logo berputar 3D) sambil memuat sesi, lalu
/// sudah login → beranda sesuai peran; belum → layar sambutan (tombol Masuk).
class _StartGate extends StatelessWidget {
  const _StartGate();

  static Future<(Session?, ServerConfig?)> _load() async => (await Session.load(), await ServerConfig.load());

  @override
  Widget build(BuildContext context) => SplashScreen(
    load: _load,
    next: (data) {
      final (session, server) = data;
      return session != null ? homeFor(session) : WelcomeScreen(server: server);
    },
  );
}
