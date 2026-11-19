import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../app/server_config.dart';
import '../widgets/ui.dart';
import 'connect_screen.dart';
import 'login_screen.dart';
import 'splash_screen.dart';

/// Layar sambutan (belum login): latar hitam, logo di tengah, satu tombol Masuk di bawah.
/// Masuk → layar Masuk bila server sudah diketahui, selain itu layar Hubungkan organisasi.
/// Logo meluncur dari splash (Hero); setelah tiba, judul → keterangan → tombol muncul bergantian.
class WelcomeScreen extends StatefulWidget {
  const WelcomeScreen({super.key, this.server});

  final ServerConfig? server;

  @override
  State<WelcomeScreen> createState() => _WelcomeScreenState();
}

class _WelcomeScreenState extends State<WelcomeScreen> with SingleTickerProviderStateMixin {
  late final AnimationController _intro = AnimationController(vsync: this, duration: const Duration(milliseconds: 1600));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    // Pengaturan aksesibilitas "kurangi animasi": langsung tampil penuh.
    if (MediaQuery.disableAnimationsOf(context)) {
      _intro.value = 1;
    } else if (!_intro.isAnimating && _intro.value == 0) {
      _intro.forward();
    }
  }

  @override
  void dispose() {
    _intro.dispose();
    super.dispose();
  }

  /// Memudar + bergeser naik [dy] px pada rentang [start]–[end] (0..1) animasi; [scale]: mengecil ke ukuran akhir.
  Widget _enter(double start, double end, Widget child, {double dy = 16, bool scale = false}) {
    final t = CurvedAnimation(
      parent: _intro,
      curve: Interval(start, end, curve: Curves.easeOutCubic),
    );
    return AnimatedBuilder(
      animation: t,
      builder: (context, child) => Opacity(
        opacity: t.value,
        child: Transform.translate(
          offset: Offset(0, (1 - t.value) * dy),
          child: scale ? Transform.scale(scale: 1.08 - 0.08 * t.value, child: child) : child,
        ),
      ),
      child: child,
    );
  }

  @override
  Widget build(BuildContext context) {
    final s = widget.server;
    final text = ShadTheme.of(context).textTheme;
    return Scaffold(
      backgroundColor: splashBlack,
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(
                child: Center(
                  child: SingleChildScrollView(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const BrandLogo(size: 144),
                        const SizedBox(height: 20),
                        _enter(
                          0.4,
                          0.75,
                          Text(
                            'Kartu Anggota',
                            textAlign: TextAlign.center,
                            style: text.h3.copyWith(color: Colors.white),
                          ),
                        ),
                        const SizedBox(height: 6),
                        _enter(
                          0.5,
                          0.85,
                          Text(
                            s != null && s.name.isNotEmpty ? s.name : 'Absensi organisasi dengan kartu NFC',
                            textAlign: TextAlign.center,
                            style: text.muted.copyWith(color: Colors.white70),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              _enter(
                0.6,
                1,
                dy: 24,
                ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 420),
                  child: FlexButton(
                    label: 'Masuk',
                    backgroundColor: Colors.white,
                    foregroundColor: splashBlack,
                    onPressed: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => s != null ? LoginScreen(server: s) : const ConnectScreen())),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
