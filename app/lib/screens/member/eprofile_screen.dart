import 'package:file_picker/file_picker.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../widgets/ui.dart';

/// Pilih foto dari galeri/kamera (bisa diganti di tes). Mengembalikan null bila batal.
typedef PickPhoto = Future<({String name, List<int> bytes})?> Function();

Future<({String name, List<int> bytes})?> _pickPhoto() async {
  final files = await FilePicker.pickFiles(type: FileType.image);
  if (files.isEmpty) return null;
  final f = files.first;
  return (name: f.name, bytes: await f.readAsBytes());
}

/// E-profil: data diri untuk didaftarkan sebagai anggota. Terkirim → kartu "menunggu pengesahan";
/// pengurus pusat memeriksa lalu mengesahkan. Mengembalikan true bila terkirim.
class EProfileScreen extends StatefulWidget {
  const EProfileScreen({super.key, required this.api, this.pickPhoto = _pickPhoto});

  final ApiClient api;
  final PickPhoto pickPhoto;

  @override
  State<EProfileScreen> createState() => _EProfileScreenState();
}

class _EProfileScreenState extends State<EProfileScreen> {
  late final Future<void> _load = _init();
  final _name = TextEditingController();
  final _no = TextEditingController();
  final _phone = TextEditingController();
  final _cohort = TextEditingController();
  String _org = '';
  String? _term;
  String _email = '';
  List<({int id, String name})> _divisions = const [];
  int? _division;
  ({String name, List<int> bytes})? _photo;
  bool _consent = false;
  bool _busy = false;
  String? _error;

  static const maxPhotoBytes = 5 * 1024 * 1024;

  Future<void> _init() async {
    final f = await widget.api.profileForm();
    _org = f.organization;
    _term = f.term;
    _email = f.email;
    _name.text = f.name;
    _divisions = f.divisions;
  }

  @override
  void dispose() {
    for (final c in [_name, _no, _phone, _cohort]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _choosePhoto() async {
    final p = await widget.pickPhoto();
    if (p == null) return;
    setState(() {
      _photo = p.bytes.length > maxPhotoBytes ? null : p;
      _error = p.bytes.length > maxPhotoBytes ? 'Foto terlalu besar (maks. 5 MB).' : null;
    });
  }

  Future<void> _submit() async {
    final problem = _name.text.trim().isEmpty
        ? 'Isi nama lengkap.'
        : _no.text.trim().isEmpty
        ? 'Isi NIM / nomor induk.'
        : !_consent
        ? 'Centang persetujuan pemrosesan data.'
        : null;
    if (problem != null) {
      setState(() => _error = problem);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.api.submitProfile(
        name: _name.text.trim(),
        memberNo: _no.text.trim(),
        phone: _phone.text.trim(),
        cohort: _cohort.text.trim(),
        divisionId: _division,
        photoBytes: _photo?.bytes,
        photoName: _photo?.name,
      );
      if (!mounted) return;
      Navigator.of(context).pop(true);
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
    Widget field(String label, Widget input, {String? hint}) => Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      spacing: 6,
      children: [
        Text(label, style: theme.textTheme.small),
        input,
        if (hint != null) Text(hint, style: theme.textTheme.muted),
      ],
    );
    return Scaffold(
      body: AppPage(
        title: 'E-profil',
        child: FutureBuilder<void>(
          future: _load,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
            if (snap.hasError) return Padding(padding: const EdgeInsets.all(16), child: ErrorBanner(snap.error is ApiException ? (snap.error as ApiException).message : 'Tidak bisa memuat formulir.'));
            return ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Text(
                  'Daftarkan keanggotaan di $_org${_term != null ? ' periode $_term' : ''}. Setelah dikirim, pengurus pusat memeriksa dan mengesahkan, lalu kartu Anda aktif.',
                  style: theme.textTheme.muted,
                ),
                const SizedBox(height: 16),
                if (_error != null) ...[ShadAlert.destructive(icon: const Icon(LucideIcons.circleAlert), description: Text(_error!)), const SizedBox(height: 12)],
                Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  spacing: 14,
                  children: [
                    field('Nama lengkap', ShadInput(controller: _name)),
                    field('NIM / nomor induk', ShadInput(controller: _no), hint: 'Dipakai sebagai nomor anggota.'),
                    field('Email', ShadInput(initialValue: _email, readOnly: true, enabled: false)),
                    field('No. HP (opsional)', ShadInput(controller: _phone, keyboardType: TextInputType.phone)),
                    field('Angkatan (opsional)', ShadInput(controller: _cohort, keyboardType: TextInputType.number)),
                    if (_divisions.isNotEmpty)
                      field(
                        'Divisi yang diminati (opsional)',
                        ShadSelect<int?>(
                          placeholder: const Text('Belum memilih'),
                          initialValue: _division,
                          onChanged: (v) => setState(() => _division = v),
                          options: [for (final d in _divisions) ShadOption<int?>(value: d.id, child: Text(d.name))],
                          selectedOptionBuilder: (context, v) => Text(_divisions.firstWhere((d) => d.id == v, orElse: () => (id: 0, name: '-')).name),
                        ),
                      ),
                    field(
                      'Foto (opsional)',
                      Row(
                        spacing: 8,
                        children: [
                          ShadButton.outline(onPressed: _choosePhoto, leading: const Icon(LucideIcons.image, size: 16), child: Text(_photo == null ? 'Pilih foto' : 'Ganti foto')),
                          if (_photo != null)
                            Expanded(
                              child: Text(_photo!.name, overflow: TextOverflow.ellipsis, style: theme.textTheme.muted),
                            ),
                        ],
                      ),
                      hint: 'Foto wajah jelas untuk kartu & pencocokan saat absensi. Maks. 5 MB.',
                    ),
                    ShadCheckbox(
                      value: _consent,
                      onChanged: (v) => setState(() => _consent = v),
                      label: Text('Saya setuju data diri saya (termasuk foto) diproses $_org untuk keperluan keanggotaan dan absensi.'),
                    ),
                    FlexButton(onPressed: _busy || !_consent ? null : _submit, label: _busy ? 'Mengirim…' : 'Kirim e-profil'),
                  ],
                ),
              ],
            );
          },
        ),
      ),
    );
  }
}
