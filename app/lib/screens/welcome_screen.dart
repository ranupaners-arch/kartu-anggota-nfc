import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../app/server_config.dart';
import '../widgets/ui.dart';
import 'connect_screen.dart';
import 'login_screen.dart';
import 'splash_screen.dart';

/// Layar sambutan (belum login): latar hitam, logo di tengah, satu tombol Masuk di bawah.
/// Masuk → layar Masuk bila server sudah diketahui, selain itu layar Hubungkan organisasi.
class WelcomeScreen extends StatelessWidget {
  const WelcomeScreen({super.key, this.server});

  final ServerConfig? server;

  @override
  Widget build(BuildContext context) {
    final s = server;
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
                        Text(
                          'Kartu Anggota',
                          textAlign: TextAlign.center,
                          style: ShadTheme.of(context).textTheme.h3.copyWith(color: Colors.white),
                        ),
                        const SizedBox(height: 6),
                        Text(
                          s != null && s.name.isNotEmpty ? s.name : 'Absensi organisasi dengan kartu NFC',
                          textAlign: TextAlign.center,
                          style: ShadTheme.of(context).textTheme.muted.copyWith(color: Colors.white70),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 420),
                child: FlexButton(
                  label: 'Masuk',
                  backgroundColor: Colors.white,
                  foregroundColor: splashBlack,
                  onPressed: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => s != null ? LoginScreen(server: s) : const ConnectScreen())),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
