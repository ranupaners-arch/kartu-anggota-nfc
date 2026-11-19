import 'package:file_picker/file_picker.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/format.dart';
import '../../widgets/ui.dart';

/// Berkas lampiran terpilih (nama + isi).
typedef PickedFile = ({String name, List<int> bytes});

/// Pemilih berkas (bisa diganti di tes).
typedef AttachmentPicker = Future<PickedFile?> Function();

const maxAttachmentBytes = 5 * 1024 * 1024;
const allowedAttachmentExt = ['jpg', 'jpeg', 'png', 'pdf'];

Future<PickedFile?> _pickWithSystem() async {
  final files = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: allowedAttachmentExt);
  if (files.isEmpty) return null;
  final f = files.first;
  return (name: f.name, bytes: await f.readAsBytes());
}

/// Formulir izin/sakit untuk satu kegiatan.
class ExcuseScreen extends StatefulWidget {
  const ExcuseScreen({super.key, required this.api, required this.event, this.picker = _pickWithSystem});

  final ApiClient api;
  final MemberEvent event;
  final AttachmentPicker picker;

  @override
  State<ExcuseScreen> createState() => _ExcuseScreenState();
}

class _ExcuseScreenState extends State<ExcuseScreen> {
  late String _type = widget.event.excuseType ?? 'izin';
  final _reason = TextEditingController();
  PickedFile? _file;
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Future<void> _pick() async {
    final f = await widget.picker();
    if (f == null) return;
    final ext = f.name.split('.').last.toLowerCase();
    setState(() {
      if (!allowedAttachmentExt.contains(ext)) {
        _error = 'Lampiran harus gambar (JPG/PNG) atau PDF.';
      } else if (f.bytes.length > maxAttachmentBytes) {
        _error = 'Lampiran maksimal 5 MB.';
      } else {
        _file = f;
        _error = null;
      }
    });
  }

  Future<void> _submit() async {
    if (_reason.text.trim().isEmpty) return setState(() => _error = 'Alasan wajib diisi.');
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.api.submitExcuse(eventId: widget.event.id, type: _type, reason: _reason.text.trim(), fileBytes: _file?.bytes, fileName: _file?.name);
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (_) {
      setState(() => _error = 'Gagal mengirim. Periksa koneksi.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    Widget typeButton(String value, String label) => Expanded(
      child: _type == value ? FlexButton(onPressed: () {}, label: label) : FlexButton(variant: ShadButtonVariant.outline, onPressed: () => setState(() => _type = value), label: label),
    );

    return AppPage(
      title: 'Ajukan Izin',
      leading: const BackButton(),
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          ShadCard(title: Text(widget.event.title), description: Text(fmtDateTime(widget.event.startsAt))),
          const SizedBox(height: 16),
          if (_error != null) ...[ShadAlert.destructive(icon: const Icon(LucideIcons.circleAlert), description: Text(_error!)), const SizedBox(height: 12)],
          Text('Jenis', style: theme.textTheme.small),
          const SizedBox(height: 6),
          Row(spacing: 8, children: [typeButton('izin', 'Izin'), typeButton('sakit', 'Sakit')]),
          const SizedBox(height: 16),
          Text('Alasan', style: theme.textTheme.small),
          const SizedBox(height: 6),
          ShadInput(controller: _reason, minLines: 3, maxLines: 6, placeholder: const Text('Tuliskan alasan singkat')),
          const SizedBox(height: 16),
          Text('Lampiran (opsional)', style: theme.textTheme.small),
          const SizedBox(height: 6),
          FlexButton(variant: ShadButtonVariant.outline, icon: LucideIcons.paperclip, onPressed: _busy ? null : _pick, label: _file == null ? 'Pilih gambar atau PDF (maks. 5 MB)' : _file!.name),
          const SizedBox(height: 24),
          FlexButton(onPressed: _busy ? null : _submit, label: _busy ? 'Mengirim…' : 'Kirim pengajuan'),
          const SizedBox(height: 8),
          Text('Pengurus akan menyetujui atau menolak. Statusnya terlihat di tab Kegiatan.', style: theme.textTheme.muted, textAlign: TextAlign.center),
        ],
      ),
    );
  }
}
