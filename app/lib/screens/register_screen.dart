import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../api/api_client.dart';
import '../api/ids.dart';
import '../app/home.dart';
import '../app/server_config.dart';
import '../app/session.dart';
import '../widgets/ui.dart';
import 'change_password_screen.dart';
import 'splash_screen.dart';

/// Daftar akun anggota baru (hanya bila organisasi membuka pendaftaran). Setelah itu langsung masuk
/// dan melihat kartu kosong untuk mengisi e-profil.
class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key, required this.server, this.api});

  final ServerConfig server;

  /// Untuk tes.
  final ApiClient? api;

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _repeat = TextEditingController();
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    for (final c in [_name, _email, _password, _repeat]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    final problem = _name.text.trim().isEmpty
        ? 'Isi nama lengkap.'
        : !_email.text.contains('@')
        ? 'Isi email yang valid.'
        : passwordProblem(_password.text, _repeat.text);
    if (problem != null) {
      setState(() => _error = problem);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final api = widget.api ?? ApiClient(baseUrl: widget.server.url);
      final user = await api.register(name: _name.text.trim(), email: _email.text.trim(), password: _password.text, deviceUuid: await deviceUuid());
      final session = Session(baseUrl: widget.server.url, token: api.token!, userName: user['name'] as String? ?? '', role: user['role'] as String? ?? 'member', userId: user['id'] as int?);
      await session.save();
      if (!mounted) return;
      Navigator.of(context).pushAndRemoveUntil(MaterialPageRoute<void>(builder: (_) => homeFor(session)), (_) => false);
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (_) {
      setState(() => _error = 'Server tidak terjangkau. Periksa koneksi.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    Widget field(String label, Widget input) => Column(crossAxisAlignment: CrossAxisAlignment.start, spacing: 6, children: [Text(label, style: theme.textTheme.small), input]);
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
                  title: const Text('Daftar akun'),
                  description: Text('Akun anggota ${widget.server.name.isNotEmpty ? widget.server.name : ''}. Setelah masuk, isi e-profil untuk mendapatkan kartu.'),
                  child: Padding(
                    padding: const EdgeInsets.only(top: 16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      spacing: 12,
                      children: [
                        if (_error != null) ShadAlert.destructive(icon: const Icon(LucideIcons.circleAlert), description: Text(_error!)),
                        field('Nama lengkap', ShadInput(controller: _name, leading: const Icon(LucideIcons.user, size: 16))),
                        field('Email', ShadInput(controller: _email, keyboardType: TextInputType.emailAddress, placeholder: const Text('nama@email.com'), leading: const Icon(LucideIcons.mail, size: 16))),
                        field('Password', ShadInput(controller: _password, obscureText: true, placeholder: const Text('Minimal 10 karakter, huruf & angka'), leading: const Icon(LucideIcons.lock, size: 16))),
                        field('Ulangi password', ShadInput(controller: _repeat, obscureText: true, leading: const Icon(LucideIcons.lock, size: 16), onSubmitted: (_) => _busy ? null : _submit())),
                        const SizedBox(height: 4),
                        FlexButton(onPressed: _busy ? null : _submit, label: _busy ? 'Mendaftar…' : 'Daftar'),
                        FlexButton(variant: ShadButtonVariant.link, small: true, onPressed: _busy ? null : () => Navigator.of(context).pop(), label: 'Sudah punya akun? Masuk'),
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
