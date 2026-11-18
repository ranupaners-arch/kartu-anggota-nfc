import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

/// Foto anggota dari server (endpoint ber-auth). Ketuk untuk memperbesar.
/// Tanpa URL, saat gagal dimuat (mis. offline), atau saat memuat: ikon pengganti.
class MemberPhoto extends StatelessWidget {
  const MemberPhoto({super.key, required this.url, required this.token, this.size = 96, this.name});

  final String? url;
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
    if (url == null) return ClipRRect(borderRadius: theme.radius, child: placeholder);

    final image = Image.network(
      url!,
      headers: _headers,
      width: size,
      height: size,
      fit: BoxFit.cover,
      semanticLabel: name == null ? 'Foto anggota' : 'Foto $name',
      errorBuilder: (_, _, _) => placeholder,
      loadingBuilder: (_, child, progress) => progress == null ? child : placeholder,
    );

    return GestureDetector(
      onTap: () => showDialog<void>(
        context: context,
        builder: (_) => Dialog(
          clipBehavior: Clip.antiAlias,
          child: Image.network(url!, headers: _headers, fit: BoxFit.contain, errorBuilder: (_, _, _) => const SizedBox(height: 120, child: Center(child: Text('Foto tidak bisa dimuat.')))),
        ),
      ),
      child: ClipRRect(borderRadius: theme.radius, child: image),
    );
  }
}
