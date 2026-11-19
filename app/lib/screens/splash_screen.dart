import 'dart:math' as math;

import 'package:material_ui/material_ui.dart';

/// Warna latar splash & sambutan (juga splash bawaan Android: @color/splash_black).
const splashBlack = Color(0xFF000000);

/// Splash: latar hitam, logo 3D berputar satu putaran pada sumbu tegak lalu berhenti menghadap depan.
/// Pindah ke layar berikutnya setelah animasi selesai DAN [load] selesai (mana yang terakhir).
class SplashScreen<T> extends StatefulWidget {
  const SplashScreen({super.key, required this.load, required this.next, this.duration = const Duration(milliseconds: 1400)});

  final Future<T> Function() load;
  final Widget Function(T data) next;
  final Duration duration;

  @override
  State<SplashScreen<T>> createState() => _SplashScreenState<T>();
}

class _SplashScreenState<T> extends State<SplashScreen<T>> with SingleTickerProviderStateMixin {
  late final AnimationController _spin = AnimationController(vsync: this, duration: widget.duration);
  late final Animation<double> _angle = CurvedAnimation(parent: _spin, curve: Curves.easeInOutCubic);

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
        transitionDuration: const Duration(milliseconds: 350),
        transitionsBuilder: (_, a, _, child) => FadeTransition(opacity: a, child: child),
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
        animation: _angle,
        builder: (context, child) => Transform(
          alignment: Alignment.center,
          // Perspektif + rotasi sumbu Y: kesan kartu/koin diputar.
          transform: Matrix4.identity()
            ..setEntry(3, 2, 0.0015)
            ..rotateY(_angle.value * 2 * math.pi),
          child: child,
        ),
        child: const BrandLogo(size: 160),
      ),
    ),
  );
}

/// Logo 3D aplikasi.
class BrandLogo extends StatelessWidget {
  const BrandLogo({super.key, this.size = 144});

  final double size;

  @override
  Widget build(BuildContext context) => Image(image: const AssetImage('assets/brand/logo-3d.png'), width: size, height: size, semanticLabel: 'Logo Kartu Anggota');
}
