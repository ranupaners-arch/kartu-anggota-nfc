import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../api/api_client.dart';
import 'member_photo.dart';

/// Rasio kartu CR80 (85,6 × 54 mm) — sama dengan kartu ATM/NFC dan PDF cetak.
const cardAspect = 85.6 / 54;

const _indigo = Color(0xFF4F46E5);
const _indigoDeep = Color(0xFF312E81);

/// Kartu anggota yang bisa dipakai ulang (halaman utama, pratinjau, dll.).
/// - [CardState.none]: kartu kosong bergaris putus-putus + ajakan isi e-profil ([onRegister]).
/// - [CardState.pending]: kartu abu dengan data e-profil + "Menunggu pengesahan".
/// - [CardState.active]: kartu indigo lengkap (logo, foto, nama, nomor, divisi, berlaku s.d.).
/// - [CardState.inactive]: kartu abu dengan status (alumni/tidak aktif).
class MemberCardView extends StatelessWidget {
  const MemberCardView({super.key, required this.info, this.token, this.onRegister});

  final MemberCardInfo info;
  final String? token;
  final VoidCallback? onRegister;

  // Kartu = objek visual berukuran tetap (seperti gambar kartu ATM): ukuran huruf sudah mengikuti
  // lebar kartu, jadi skala huruf sistem tidak diterapkan di dalamnya (teks penjelasan di luar kartu
  // tetap mengikuti). Isi kartu tetap terbaca pembaca layar lewat Semantics.
  @override
  Widget build(BuildContext context) => MediaQuery.withNoTextScaling(
    child: AspectRatio(
      aspectRatio: cardAspect,
      child: LayoutBuilder(
        builder: (context, box) => info.state == CardState.none ? _blank(context, box.maxWidth) : _filled(context, box.maxWidth),
      ),
    ),
  );

  Widget _blank(BuildContext context, double w) {
    final theme = ShadTheme.of(context);
    return Semantics(
      button: onRegister != null,
      label: 'Kartu anggota kosong. Daftarkan keanggotaan dengan e-profil.',
      child: Material(
        color: theme.colorScheme.card,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(w * 0.05),
          side: BorderSide(color: theme.colorScheme.mutedForeground.withValues(alpha: 0.5), width: 1.5),
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onRegister,
          child: Padding(
            padding: EdgeInsets.all(w * 0.06),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Opacity(opacity: 0.5, child: Image.asset('assets/brand/logo-2d.webp', width: w * 0.08, height: w * 0.08)),
                    SizedBox(width: w * 0.025),
                    Expanded(
                      child: Text(info.organization ?? 'Kartu Anggota', maxLines: 1, overflow: TextOverflow.ellipsis, style: theme.textTheme.small.copyWith(color: theme.colorScheme.mutedForeground)),
                    ),
                  ],
                ),
                Expanded(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(LucideIcons.idCard, size: w * 0.08, color: _indigo),
                      SizedBox(height: w * 0.015),
                      Text('Daftarkan keanggotaan\ndengan e-profil', maxLines: 2, overflow: TextOverflow.ellipsis, style: theme.textTheme.large.copyWith(fontSize: w * 0.05, height: 1.2)),
                      SizedBox(height: w * 0.008),
                      Text('Isi data diri, lalu pengurus pusat mengesahkan dan menerbitkan kartu Anda.', maxLines: 2, overflow: TextOverflow.ellipsis, style: theme.textTheme.muted.copyWith(fontSize: w * 0.033, height: 1.25)),
                    ],
                  ),
                ),
                if (onRegister != null)
                  Row(
                    children: [
                      Text('Isi e-profil', style: theme.textTheme.small.copyWith(color: _indigo, fontWeight: FontWeight.w600, fontSize: w * 0.036)),
                      Icon(LucideIcons.chevronRight, size: w * 0.045, color: _indigo),
                    ],
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _filled(BuildContext context, double w) {
    final active = info.state == CardState.active;
    final fg = Colors.white;
    final valid = info.validUntil == null ? null : '${info.validUntil!.month.toString().padLeft(2, '0')}/${(info.validUntil!.year % 100).toString().padLeft(2, '0')}';
    final badge = switch (info.state) {
      CardState.pending => 'MENUNGGU PENGESAHAN',
      CardState.inactive => info.status == 'alumni' ? 'ALUMNI' : 'TIDAK AKTIF',
      _ => null,
    };
    final small = TextStyle(color: fg.withValues(alpha: 0.75), fontSize: w * 0.03, letterSpacing: 0.6);
    return Semantics(
      label: [
        'Kartu anggota ${info.organization ?? ''}',
        if (info.name != null) info.name!,
        if (info.memberNo != null) 'nomor ${info.memberNo}',
        if (badge != null) badge.toLowerCase(),
      ].join(', '),
      child: ExcludeSemantics(
        child: Container(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(w * 0.05),
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: active ? const [_indigo, _indigoDeep] : const [Color(0xFF6B7280), Color(0xFF374151)],
            ),
            boxShadow: [BoxShadow(color: (active ? _indigo : Colors.black).withValues(alpha: 0.3), blurRadius: 18, offset: const Offset(0, 8))],
          ),
          clipBehavior: Clip.antiAlias,
          child: Stack(
            children: [
              // Hiasan: dua lingkaran samar seperti kartu bank.
              Positioned(right: -w * 0.18, top: -w * 0.2, child: _circle(w * 0.6, 0.08)),
              Positioned(right: w * 0.12, bottom: -w * 0.32, child: _circle(w * 0.55, 0.06)),
              Padding(
                padding: EdgeInsets.all(w * 0.055),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Image.asset('assets/brand/logo-2d.webp', width: w * 0.085, height: w * 0.085),
                        SizedBox(width: w * 0.025),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(info.organization ?? 'Kartu Anggota', maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: fg, fontWeight: FontWeight.w700, fontSize: w * 0.04)),
                              Text('KARTU ANGGOTA', style: small),
                            ],
                          ),
                        ),
                        Icon(LucideIcons.nfc, color: fg.withValues(alpha: 0.85), size: w * 0.065),
                      ],
                    ),
                    const Spacer(),
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              // "Chip" ala kartu bank.
                              Container(
                                width: w * 0.1,
                                height: w * 0.075,
                                margin: EdgeInsets.only(bottom: w * 0.03),
                                decoration: BoxDecoration(
                                  borderRadius: BorderRadius.circular(w * 0.012),
                                  gradient: const LinearGradient(colors: [Color(0xFFFDE68A), Color(0xFFD4A017)]),
                                ),
                              ),
                              if (badge != null)
                                Container(
                                  margin: EdgeInsets.only(bottom: w * 0.02),
                                  padding: EdgeInsets.symmetric(horizontal: w * 0.02, vertical: w * 0.006),
                                  decoration: BoxDecoration(color: Colors.white.withValues(alpha: 0.2), borderRadius: BorderRadius.circular(w * 0.01)),
                                  child: Text(badge, style: small.copyWith(color: fg, fontWeight: FontWeight.w700)),
                                ),
                              Text(info.memberNo ?? '—', style: TextStyle(color: fg, fontSize: w * 0.05, letterSpacing: w * 0.006, fontFeatures: const [FontFeature.tabularFigures()])),
                              SizedBox(height: w * 0.008),
                              Text((info.name ?? '').toUpperCase(), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: fg, fontWeight: FontWeight.w600, fontSize: w * 0.042, letterSpacing: 0.4)),
                              SizedBox(height: w * 0.006),
                              Text(
                                [if (info.division != null) info.division!, if (valid != null) 'BERLAKU S.D. $valid'].join('  ·  '),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: small,
                              ),
                            ],
                          ),
                        ),
                        SizedBox(width: w * 0.03),
                        Container(
                          decoration: BoxDecoration(border: Border.all(color: Colors.white.withValues(alpha: 0.85), width: 2), borderRadius: BorderRadius.circular(w * 0.025)),
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(w * 0.02),
                            child: SizedBox(
                              width: w * 0.2,
                              height: w * 0.25,
                              child: info.photoUrl == null
                                  // Tanpa foto: inisial di atas latar putih transparan (serasi dengan kartu).
                                  ? Container(
                                      color: Colors.white.withValues(alpha: 0.15),
                                      alignment: Alignment.center,
                                      child: Text(_initials(info.name), style: TextStyle(color: fg, fontWeight: FontWeight.w700, fontSize: w * 0.07)),
                                    )
                                  : MemberPhoto(url: info.photoUrl, token: token, size: w * 0.25, name: info.name),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  static String _initials(String? name) => (name ?? '')
      .trim()
      .split(RegExp(r'\s+'))
      .where((w) => w.isNotEmpty)
      .take(2)
      .map((w) => w[0].toUpperCase())
      .join();

  Widget _circle(double size, double alpha) => Container(
    width: size,
    height: size,
    decoration: BoxDecoration(shape: BoxShape.circle, color: Colors.white.withValues(alpha: alpha)),
  );
}
