import 'package:file_picker/file_picker.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/format.dart';
import '../../widgets/ui.dart';
import 'eprofile_screen.dart' show PickPhoto;

Future<({String name, List<int> bytes})?> _pickPhoto() async {
  final files = await FilePicker.pickFiles(type: FileType.image);
  if (files.isEmpty) return null;
  final f = files.first;
  return (name: f.name, bytes: await f.readAsBytes());
}

/// Ajukan perubahan nama, no. HP, atau foto. Data baru berlaku setelah disetujui Super Admin;
/// pengajuan baru menggantikan yang masih menunggu.
class ProfileChangePage extends StatefulWidget {
  const ProfileChangePage({super.key, required this.api, this.pickPhoto = _pickPhoto});

  final ApiClient api;
  final PickPhoto pickPhoto;

  @override
  State<ProfileChangePage> createState() => _ProfileChangePageState();
}

class _ProfileChangePageState extends State<ProfileChangePage> {
  late Future<ProfileChangeStatus> _status = _load();
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _reason = TextEditingController();
  ({String name, List<int> bytes})? _photo;
  bool _busy = false;
  String? _error;
  bool _sent = false;

  Future<ProfileChangeStatus> _load() async {
    final s = await widget.api.profileChange();
    _name.text = s.currentName;
    _phone.text = s.currentPhone ?? '';
    return s;
  }

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    _reason.dispose();
    super.dispose();
  }

  Future<void> _pick() async {
    final p = await widget.pickPhoto();
    if (p != null && mounted) setState(() => _photo = p);
  }

  Future<void> _submit(ProfileChangeStatus s) async {
    final name = _name.text.trim(), phone = _phone.text.trim();
    final changedName = name.isNotEmpty && name != s.currentName;
    final changedPhone = phone.isNotEmpty && phone != (s.currentPhone ?? '');
    if (!changedName && !changedPhone && _photo == null) {
      setState(() => _error = 'Belum ada data yang diubah.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final fresh = await widget.api.requestProfileChange(
        name: changedName ? name : null,
        phone: changedPhone ? phone : null,
        reason: _reason.text.trim(),
        photoBytes: _photo?.bytes,
        photoName: _photo?.name,
      );
      if (!mounted) return;
      setState(() {
        _status = Future.value(fresh);
        _photo = null;
        _reason.clear();
        _sent = true;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = 'Server tidak terjangkau.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String _pendingSummary(({String? name, String? phone, bool photo, String status, String? note, DateTime? at}) p) =>
      [if (p.name != null) 'nama → ${p.name}', if (p.phone != null) 'no. HP → ${p.phone}', if (p.photo) 'foto baru'].join(', ');

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    return FutureBuilder<ProfileChangeStatus>(
      future: _status,
      builder: (context, snap) {
        if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
        if (snap.hasError) {
          return ListView(padding: const EdgeInsets.all(16), children: [ErrorBanner(snap.error is ApiException ? (snap.error as ApiException).message : 'Tidak bisa memuat data.')]);
        }
        final s = snap.data!;
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (_sent) ...[
              const ShadAlert(
                icon: Icon(LucideIcons.circleCheck),
                title: Text('Pengajuan terkirim'),
                description: Text('Data baru berlaku setelah disetujui pengurus.'),
              ),
              const SizedBox(height: 12),
            ],
            if (s.pending case final p?) ...[
              ShadAlert(
                icon: const Icon(LucideIcons.hourglass),
                title: const Text('Menunggu persetujuan pengurus'),
                description: Text('${_pendingSummary(p)}${p.at == null ? '' : ' · diajukan ${fmtDate(p.at!)}'}. Mengirim pengajuan baru akan menggantikannya.'),
              ),
              const SizedBox(height: 12),
            ] else if (s.lastDecision case final d?) ...[
              ShadAlert(
                icon: Icon(d.status == 'approved' ? LucideIcons.circleCheck : LucideIcons.circleX),
                title: Text(d.status == 'approved' ? 'Perubahan terakhir disetujui' : 'Perubahan terakhir ditolak'),
                description: Text(d.note == null || d.note!.isEmpty ? (d.status == 'approved' ? 'Data diri Anda sudah diperbarui.' : 'Data lama tetap dipakai.') : 'Catatan pengurus: ${d.note}'),
              ),
              const SizedBox(height: 12),
            ],
            Text('NIM, divisi, dan status hanya bisa diubah pengurus.', style: theme.textTheme.muted),
            const SizedBox(height: 16),
            if (_error != null) ...[ErrorBanner(_error!), const SizedBox(height: 12)],
            ShadInputFormField(controller: _name, label: const Text('Nama lengkap'), maxLength: 150, autofillHints: const [AutofillHints.name]),
            const SizedBox(height: 12),
            ShadInputFormField(controller: _phone, label: const Text('No. HP'), keyboardType: TextInputType.phone, autofillHints: const [AutofillHints.telephoneNumber]),
            const SizedBox(height: 12),
            Text('Foto (opsional)', style: theme.textTheme.small.copyWith(fontWeight: FontWeight.w600)),
            const SizedBox(height: 6),
            FlexButton(label: _photo == null ? 'Pilih foto baru' : _photo!.name, icon: LucideIcons.image, variant: ShadButtonVariant.outline, onPressed: _pick),
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text('Foto wajah jelas, tegak, latar polos. JPG/PNG/WebP, maks. 5 MB.', style: theme.textTheme.muted.copyWith(fontSize: 12)),
            ),
            const SizedBox(height: 12),
            ShadInputFormField(controller: _reason, label: const Text('Alasan (opsional)'), placeholder: const Text('Mis. salah ketik nama, ganti nomor HP'), maxLength: 300),
            const SizedBox(height: 20),
            FlexButton(label: _busy ? 'Mengirim…' : 'Kirim pengajuan', icon: LucideIcons.send, enabled: !_busy, onPressed: () => _submit(s)),
          ],
        );
      },
    );
  }
}
