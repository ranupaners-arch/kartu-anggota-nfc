import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../api/api_client.dart';
import '../app/home.dart';
import '../app/server_config.dart';
import '../app/session.dart';
import '../widgets/ui.dart';
import 'login_screen.dart';
import 'splash_screen.dart';

/// Aturan sama dengan server: minimal 10 karakter, berisi huruf dan angka. null = valid.
String? passwordProblem(String next, String repeat) {
  if (next.length < 10) return 'Password baru minimal 10 karakter.';
  if (!RegExp(r'[A-Za-z]').hasMatch(next) || !RegExp(r'\d').hasMatch(next)) return 'Password baru harus berisi huruf dan angka.';
  if (next != repeat) return 'Ulangi password baru belum sama.';
  return null;
}

/// Wajib ganti password sementara (dibuat pengurus) sebelum memakai app. Sesi baru disimpan setelah
/// berhasil, jadi bila app ditutup di tengah jalan, pengguna masuk lagi dengan password sementara.
class ChangePasswordScreen extends StatefulWidget {
  const ChangePasswordScreen({super.key, required this.api, required this.session, required this.currentPassword, required this.server});

  final ApiClient api;
  final Session session;
  final String currentPassword;
  final ServerConfig server;

  @override
  State<ChangePasswordScreen> createState() => _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends State<ChangePasswordScreen> {
  final _next = TextEditingController();
  final _repeat = TextEditingController();
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    _next.dispose();
    _repeat.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final problem = passwordProblem(_next.text, _repeat.text);
    if (problem != null) {
      setState(() => _error = problem);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.api.changePassword(widget.currentPassword, _next.text);
      await widget.session.save();
      if (!mounted) return;
      // Bersihkan tumpukan layar (sambutan/masuk) agar layar utama tidak punya tombol kembali ke login.
      Navigator.of(context).pushAndRemoveUntil(MaterialPageRoute<void>(builder: (_) => homeFor(widget.session)), (_) => false);
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (_) {
      setState(() => _error = 'Server tidak terjangkau. Periksa koneksi.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _cancel() async {
    await widget.api.logout();
    if (!mounted) return;
    Navigator.of(context).pushReplacement(MaterialPageRoute<void>(builder: (_) => LoginScreen(server: widget.server)));
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return Scaffold(
      backgroundColor: splashBlack,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: AuthLayout(
                child: ShadCard(
                  title: const Text('Buat password baru'),
                  description: Text('Halo ${widget.session.userName}. Ganti password sementara Anda dulu.'),
                  child: Padding(
                    padding: const EdgeInsets.only(top: 16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      spacing: 12,
                      children: [
                        if (_error != null) ShadAlert.destructive(icon: const Icon(LucideIcons.circleAlert), description: Text(_error!)),
                        Text('Password baru', style: theme.textTheme.small),
                        ShadInput(controller: _next, obscureText: true, leading: const Icon(LucideIcons.lock, size: 16), placeholder: const Text('Minimal 10 karakter, huruf & angka')),
                        Text('Ulangi password baru', style: theme.textTheme.small),
                        ShadInput(controller: _repeat, obscureText: true, leading: const Icon(LucideIcons.lock, size: 16), onSubmitted: (_) => _busy ? null : _save()),
                        const SizedBox(height: 4),
                        FlexButton(onPressed: _busy ? null : _save, label: _busy ? 'Menyimpan…' : 'Simpan & lanjut'),
                        FlexButton(variant: ShadButtonVariant.link, small: true, onPressed: _busy ? null : _cancel, label: 'Batal & keluar'),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
