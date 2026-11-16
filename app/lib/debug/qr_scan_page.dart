import 'package:flutter/services.dart';
import 'package:material_ui/material_ui.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_client.dart';
import '../api/ids.dart';

/// Layar uji sementara: login petugas, pilih kegiatan, scan QR anggota.
class QrScanPage extends StatefulWidget {
  const QrScanPage({super.key});

  @override
  State<QrScanPage> createState() => _QrScanPageState();
}

class _QrScanPageState extends State<QrScanPage> {
  final _url = TextEditingController(text: 'http://192.168.1.10:8000');
  final _email = TextEditingController(text: 'petugas@hmps.test');
  final _password = TextEditingController(text: 'password');
  ApiClient? _api;
  List<EventSummary> _events = const [];
  EventSummary? _event;
  ScanResult? _last;
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    SharedPreferences.getInstance().then((p) {
      final saved = p.getString('server_url');
      if (saved != null && mounted) setState(() => _url.text = saved);
    });
  }

  Future<void> _login() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final url = _url.text.trim();
    if (!url.startsWith('http://')) {
      setState(() {
        _busy = false;
        _error = 'URL harus diawali http:// (server uji tidak memakai HTTPS), contoh http://100.84.251.14:8000';
      });
      return;
    }
    try {
      final api = ApiClient(baseUrl: url);
      await api.login(_email.text.trim(), _password.text, deviceUuid: await deviceUuid());
      final events = await api.ongoingEvents();
      (await SharedPreferences.getInstance()).setString('server_url', _url.text.trim());
      setState(() {
        _api = api;
        _events = events;
        _event = events.firstOrNull;
      });
    } catch (e) {
      setState(() => _error = '$e');
    } finally {
      setState(() => _busy = false);
    }
  }

  Future<void> _onDetect(BarcodeCapture capture) async {
    final raw = capture.barcodes.firstOrNull?.rawValue;
    if (_busy || raw == null || _event == null) return;
    setState(() => _busy = true);
    try {
      final r = await _api!.scan(_event!.id, raw, source: 'qr');
      HapticFeedback.heavyImpact();
      setState(() {
        _last = r;
        _error = null;
      });
    } catch (e) {
      setState(() => _error = '$e');
    }
    await Future<void>.delayed(const Duration(seconds: 2)); // jeda antar-scan
    if (mounted) setState(() => _busy = false);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Uji QR (petugas)')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_api == null) ...[
            TextField(controller: _url, decoration: const InputDecoration(labelText: 'URL server (IP laptop)')),
            TextField(controller: _email, decoration: const InputDecoration(labelText: 'Email')),
            TextField(controller: _password, obscureText: true, decoration: const InputDecoration(labelText: 'Password')),
            const SizedBox(height: 12),
            FilledButton(onPressed: _busy ? null : _login, child: const Text('Login')),
          ] else ...[
            if (_events.isEmpty)
              const Text('Tidak ada kegiatan berlangsung.')
            else
              DropdownButton<EventSummary>(
                isExpanded: true,
                value: _event,
                items: [for (final e in _events) DropdownMenuItem(value: e, child: Text(e.title))],
                onChanged: (e) => setState(() => _event = e),
              ),
            const SizedBox(height: 12),
            if (_event != null)
              AspectRatio(
                aspectRatio: 1,
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(12),
                  child: MobileScanner(onDetect: _onDetect),
                ),
              ),
          ],
          const SizedBox(height: 12),
          if (_last != null) _ResultCard(_last!),
          if (_error != null) Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
        ],
      ),
    );
  }
}

class _ResultCard extends StatelessWidget {
  const _ResultCard(this.r);
  final ScanResult r;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Card(
      color: r.ok ? cs.primaryContainer : cs.errorContainer,
      child: ListTile(
        title: Text(r.memberName ?? r.status.toUpperCase()),
        subtitle: Text([r.memberNo, r.attendanceStatus, r.message].whereType<String>().join(' · ')),
      ),
    );
  }
}
