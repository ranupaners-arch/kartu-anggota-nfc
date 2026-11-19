import 'dart:math' as math;

import 'package:material_ui/material_ui.dart';

/// Warna latar splash & sambutan (juga splash bawaan Android: @color/splash_black).
const splashBlack = Color(0xFF000000);

/// Splash: latar hitam; logo muncul lembut, berputar satu putaran pada sumbu tegak (percepatan &
/// perlambatan halus), lalu diam sejenak. Pindah setelah animasi selesai DAN [load] selesai; logo
/// meluncur (Hero) ke posisinya di layar berikutnya, tidak melompat.
class SplashScreen<T> extends StatefulWidget {
  const SplashScreen({super.key, required this.load, required this.next, this.duration = const Duration(milliseconds: 2600)});

  final Future<T> Function() load;
  final Widget Function(T data) next;
  final Duration duration;

  @override
  State<SplashScreen<T>> createState() => _SplashScreenState<T>();
}

class _SplashScreenState<T> extends State<SplashScreen<T>> with SingleTickerProviderStateMixin {
  late final AnimationController _spin = AnimationController(vsync: this, duration: widget.duration);

  // 0–25%: muncul (memudar + membesar); 10–80%: satu putaran; 80–100%: diam.
  late final Animation<double> _appear = CurvedAnimation(
    parent: _spin,
    curve: const Interval(0, 0.25, curve: Curves.easeOutCubic),
  );
  late final Animation<double> _angle = CurvedAnimation(
    parent: _spin,
    curve: const Interval(0.1, 0.8, curve: Curves.easeInOutSine),
  );

  @override
  void initState() {
    super.initState();
    _start();
  }

  Future<void> _start() async {
    final results = await Future.wait<Object?>([widget.load(), _spin.forward().orCancel.catchError((_) {})]);
    if (!mounted) return;
    final next = widget.next(results.first as T);
    Navigator.of(context).pushReplacement(
      PageRouteBuilder<void>(
        pageBuilder: (_, _, _) => next,
        transitionDuration: const Duration(milliseconds: 800),
        reverseTransitionDuration: const Duration(milliseconds: 400),
        transitionsBuilder: (_, a, _, child) => FadeTransition(
          opacity: CurvedAnimation(parent: a, curve: Curves.easeInOut),
          child: child,
        ),
      ),
    );
  }

  @override
  void dispose() {
    _spin.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: splashBlack,
    body: Center(
      child: AnimatedBuilder(
        animation: _spin,
        builder: (context, child) {
          final grow = 0.85 + 0.15 * _appear.value;
          return Opacity(
            opacity: _appear.value,
            child: Transform(
              alignment: Alignment.center,
              // Perspektif + rotasi sumbu Y: kesan kartu/koin diputar; sedikit membesar saat muncul.
              transform: Matrix4.identity()
                ..setEntry(3, 2, 0.0012)
                ..rotateY(_angle.value * 2 * math.pi)
                ..multiply(Matrix4.diagonal3Values(grow, grow, 1)),
              child: child,
            ),
          );
        },
        child: const BrandLogo(size: 160),
      ),
    ),
  );
}

/// Logo 3D aplikasi. Dibungkus Hero agar meluncur mulus antar-layar (splash → sambutan).
class BrandLogo extends StatelessWidget {
  const BrandLogo({super.key, this.size = 144});

  final double size;

  static const heroTag = 'brand-logo';

  @override
  Widget build(BuildContext context) => Hero(
    tag: heroTag,
    child: Image(image: const AssetImage('assets/brand/logo-3d.png'), width: size, height: size, semanticLabel: 'Logo Kartu Anggota'),
  );
}
