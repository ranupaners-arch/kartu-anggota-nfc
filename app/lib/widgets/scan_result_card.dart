import 'dart:io';

import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../api/api_client.dart';
import 'member_photo.dart';

/// Hasil scan terakhir: hijau (hadir), kuning (telat), biru (sudah absen), merah (ditolak).
class ScanResultCard extends StatelessWidget {
  const ScanResultCard({super.key, required this.result, this.token, this.photoFile});

  /// Foto tersimpan di HP untuk anggota ini (offline).
  final File? photoFile;

  final ScanResult result;

  /// Token sesi untuk memuat foto anggota.
  final String? token;

  static const _labels = {
    'duplicate': 'Sudah absen',
    'blocked': 'Kartu diblokir',
    'invalid': 'Tidak dikenali',
    'not_target': 'Bukan peserta',
    'self_scan': 'Kartu sendiri',
    'outside_window': 'Di luar jam check-in',
    'clock_drift': 'Jam HP tidak sesuai',
    'error': 'Gagal',
  };

  ({Color color, IconData icon, String label}) _style() {
    if (result.ok) {
      final late = result.attendanceStatus == 'telat';
      return (color: late ? const Color(0xFFB45309) : const Color(0xFF047857), icon: late ? LucideIcons.clock : LucideIcons.circleCheck, label: late ? 'Telat' : 'Hadir');
    }
    if (result.status == 'duplicate') return (color: const Color(0xFF0369A1), icon: LucideIcons.info, label: _labels['duplicate']!);
    return (color: const Color(0xFFB91C1C), icon: LucideIcons.circleX, label: _labels[result.status] ?? 'Ditolak');
  }

  @override
  Widget build(BuildContext context) {
    final theme = ShadTheme.of(context);
    final s = _style();
    return ShadCard(
      backgroundColor: s.color.withValues(alpha: 0.08),
      border: ShadBorder.all(color: s.color.withValues(alpha: 0.35)),
      child: Row(
        spacing: 12,
        children: [
          if (result.memberName != null) MemberPhoto(url: result.photoUrl, token: token, name: result.memberName, file: photoFile) else Icon(s.icon, color: s.color, size: 36),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              spacing: 2,
              children: [
                Wrap(
                  spacing: 6,
                  runSpacing: 4,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    if (result.memberName != null) Icon(s.icon, color: s.color, size: 16),
                    Text(
                      s.label,
                      style: theme.textTheme.small.copyWith(color: s.color, fontWeight: FontWeight.w600),
                    ),
                    if (result.offline) ShadBadge.outline(child: const Text('Offline')),
                  ],
                ),
                Text(result.memberName ?? result.message, style: theme.textTheme.h4),
                if (result.memberName != null) Text([result.memberNo, result.division, result.message].whereType<String>().join(' · '), style: theme.textTheme.muted),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
