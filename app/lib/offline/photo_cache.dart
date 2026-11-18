import 'dart:async';
import 'dart:io';

import 'package:path_provider/path_provider.dart';

import 'roster.dart';

/// Foto peserta disimpan di HP agar tetap tampil saat offline (dan lebih cepat saat online).
///
/// Nama berkas `<memberId>_<versi>.jpg`; versi diambil dari `?v=` pada URL foto dari server dan
/// berubah tiap foto diganti, jadi foto lama otomatis tidak dipakai lalu dihapus.
class PhotoCache {
  PhotoCache(this.dir);

  final Directory dir;

  static Future<PhotoCache> open() async {
    final base = await getApplicationSupportDirectory();
    return PhotoCache(Directory('${base.path}/photos'));
  }

  /// Versi foto dari URL; null bila URL tidak berversi (tidak di-cache, agar tidak basi).
  static String? versionOf(String url) {
    final v = Uri.tryParse(url)?.queryParameters['v'];
    return v != null && RegExp(r'^[0-9a-f]{4,64}$').hasMatch(v) ? v : null;
  }

  File? _fileFor(int memberId, String url) {
    final v = versionOf(url);
    return v == null ? null : File('${dir.path}/${memberId}_$v.jpg');
  }

  /// Berkas foto yang sudah tersimpan untuk anggota & URL ini, atau null.
  File? cached(int? memberId, String? url) {
    if (memberId == null || url == null) return null;
    final f = _fileFor(memberId, url);
    return f != null && f.existsSync() ? f : null;
  }

  /// Unduh foto peserta yang belum tersimpan (berurutan, tidak membebani server).
  /// Gagal satu foto tidak menghentikan yang lain. Kembalikan jumlah foto baru.
  Future<int> sync(Roster roster, Future<List<int>> Function(String url) download) async {
    await dir.create(recursive: true);
    var fetched = 0;
    for (final m in roster.members) {
      final url = m.photoUrl;
      if (url == null) continue;
      final f = _fileFor(m.id, url);
      if (f == null || f.existsSync()) continue;
      try {
        final bytes = await download(url);
        if (bytes.isEmpty) continue;
        final tmp = File('${f.path}.part');
        await tmp.writeAsBytes(bytes, flush: true);
        await tmp.rename(f.path); // tulis atomik: tidak ada berkas setengah jadi
        fetched++;
        _removeOldVersions(m.id, keep: f.path);
      } catch (_) {
        // Offline / foto terhapus di server: coba lagi saat roster dimuat berikutnya.
      }
    }
    return fetched;
  }

  void _removeOldVersions(int memberId, {required String keep}) {
    for (final e in dir.listSync()) {
      final name = e.uri.pathSegments.last;
      if (e is File && name.startsWith('${memberId}_') && e.path != keep) {
        try {
          e.deleteSync();
        } catch (_) {}
      }
    }
  }
}
