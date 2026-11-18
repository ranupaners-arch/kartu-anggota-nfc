import 'dart:io';

import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

/// Foto anggota: dari berkas yang tersimpan di HP bila ada (juga saat offline), selain itu dari
/// server (endpoint ber-auth). Ketuk untuk memperbesar.
/// Tanpa foto, saat gagal dimuat (mis. offline tanpa cache), atau saat memuat: ikon pengganti.
class MemberPhoto extends StatelessWidget {
  const MemberPhoto({super.key, required this.url, required this.token, this.size = 96, this.name, this.file});

  final String? url;

  /// Foto tersimpan di HP (lihat PhotoCache); diutamakan daripada [url].
  final File? file;
  final String? token;
  final double size;
  final String? name;

  Map<String, String> get _headers => {if (token != null) 'Authorization': 'Bearer $token'};

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    final placeholder = Container(
      width: size,
      height: size,
      color: theme.colorScheme.muted,
      child: Icon(LucideIcons.userRound, size: size * 0.45, color: theme.colorScheme.mutedForeground),
    );
    if (file == null && url == null) return ClipRRect(borderRadius: theme.radius, child: placeholder);

    final label = name == null ? 'Foto anggota' : 'Foto $name';
    final image = file != null
        ? Image.file(file!, width: size, height: size, fit: BoxFit.cover, semanticLabel: label, errorBuilder: (_, _, _) => placeholder)
        : Image.network(
            url!,
            headers: _headers,
            width: size,
            height: size,
            fit: BoxFit.cover,
            semanticLabel: label,
            errorBuilder: (_, _, _) => placeholder,
            loadingBuilder: (_, child, progress) => progress == null ? child : placeholder,
          );
    Widget large() {
      Widget error(BuildContext _, Object _, StackTrace? _) => const SizedBox(height: 120, child: Center(child: Text('Foto tidak bisa dimuat.')));
      return file != null ? Image.file(file!, fit: BoxFit.contain, errorBuilder: error) : Image.network(url!, headers: _headers, fit: BoxFit.contain, errorBuilder: error);
    }

    return GestureDetector(
      onTap: () => showDialog<void>(
        context: context,
        builder: (_) => Dialog(
          clipBehavior: Clip.antiAlias,
          child: large(),
        ),
      ),
      child: ClipRRect(borderRadius: theme.radius, child: image),
    );
  }
}
