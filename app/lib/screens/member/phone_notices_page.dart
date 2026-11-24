import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../notify/phone_notices.dart';
import '../../widgets/ui.dart';

/// Nyalakan/matikan notifikasi di bilah status HP.
class PhoneNoticesPage extends StatefulWidget {
  const PhoneNoticesPage({super.key});

  @override
  State<PhoneNoticesPage> createState() => _PhoneNoticesPageState();
}

class _PhoneNoticesPageState extends State<PhoneNoticesPage> {
  bool? _on;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    PhoneNotices.enabled().then((v) {
      if (mounted) setState(() => _on = v);
    });
  }

  Future<void> _toggle() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    String? error;
    try {
      if (_on ?? false) {
        await PhoneNotices.disable();
      } else if (!await PhoneNotices.enable()) {
        error = 'Izin notifikasi belum diberikan. Buka Setelan HP → Aplikasi → Kartu Anggota → Notifikasi, lalu coba lagi.';
      }
    } catch (_) {
      error = 'Notifikasi tidak bisa diatur di HP ini.';
    }
    final on = await PhoneNotices.enabled();
    if (!mounted) return;
    setState(() {
      _busy = false;
      _on = on;
      _error = error;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    final on = _on ?? false;
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (_error != null) ...[ErrorBanner(_error!), const SizedBox(height: 12)],
        SectionCard(
          title: on ? 'Notifikasi HP menyala' : 'Notifikasi HP mati',
          icon: on ? LucideIcons.bellRing : LucideIcons.bellOff,
          description: 'Pengumuman, keputusan izin, tagihan, dan pengingat kegiatan muncul walau app ditutup.',
          child: FlexButton(label: on ? 'Matikan' : 'Nyalakan', variant: on ? ShadButtonVariant.outline : ShadButtonVariant.primary, enabled: _on != null && !_busy, onPressed: _toggle),
        ),
        const SizedBox(height: 12),
        Text(
          'App memeriksa server sekitar tiap 30 menit saat ada internet. Android bisa menundanya untuk menghemat baterai, jadi notifikasi bisa terlambat. '
          'Bila tidak muncul, izinkan app berjalan di latar belakang di setelan baterai HP.',
          style: theme.textTheme.muted,
        ),
      ],
    );
  }
}
