import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_client.dart';
import '../app/session.dart';
import '../screens/member/notifications_page.dart';
import 'android_notices.dart';

/// Bagian khusus perangkat (Android); diganti di tes.
abstract interface class NoticePlatform {
  Future<bool> requestPermission();
  Future<void> schedule();
  Future<void> unschedule();
  Future<void> show(MemberNotice notice);
  Future<void> showSummary(int count);
  Future<void> cancelAll();

  /// Panggil [onTap] saat notifikasi diketuk, termasuk ketukan yang baru saja membuka app.
  Future<void> listen(void Function() onTap);
}

/// Notifikasi di bilah status HP: server diperiksa berkala di latar belakang, lalu yang baru ditampilkan
/// sebagai notifikasi lokal. Tanpa Firebase, jadi berlaku untuk server organisasi mana pun; bisa terlambat
/// (Android menentukan waktunya). Mati sampai anggota menyalakannya.
abstract final class PhoneNotices {
  static NoticePlatform platform = const AndroidNotices();

  /// Lebih dari ini diringkas menjadi satu notifikasi.
  static const maxShown = 4;
  static const maxAge = Duration(days: 3);

  static String _enabledKey(String owner) => 'notif_phone@$owner';
  static String _shownKey(String owner) => 'notif_shown@$owner';

  static Future<bool> enabled() async {
    final owner = (await Session.load())?.owner;
    return owner != null && ((await SharedPreferences.getInstance()).getBool(_enabledKey(owner)) ?? false);
  }

  /// Nyalakan; false bila izin notifikasi ditolak atau sesi belum dikenali.
  static Future<bool> enable() async {
    final owner = (await Session.load())?.owner;
    if (owner == null || !await platform.requestPermission()) return false;
    final p = await SharedPreferences.getInstance();
    await platform.schedule();
    await p.setBool(_enabledKey(owner), true);
    return true;
  }

  static Future<void> disable() async {
    final owner = (await Session.load())?.owner;
    if (owner != null) {
      final p = await SharedPreferences.getInstance();
      await p.remove(_enabledKey(owner));
      await p.remove(_shownKey(owner));
    }
    await _stop();
  }

  /// Layar anggota dibuka: pasang penangan ketukan & pastikan pemeriksaan terjadwal bila dinyalakan.
  static Future<void> attach(void Function() onTap) async {
    try {
      if (!await enabled()) return;
      await platform.listen(onTap);
      await platform.schedule();
    } catch (_) {
      // plugin tidak tersedia: app tetap jalan tanpa notifikasi HP
    }
  }

  /// Keluar akun: berhenti memeriksa & hapus notifikasi yang masih tampil (pilihan akun ini tetap diingat).
  static Future<void> signedOut(String? owner) async {
    if (owner != null) await (await SharedPreferences.getInstance()).remove(_shownKey(owner));
    await _stop();
  }

  static Future<void> clearShown() async {
    try {
      await platform.cancelAll();
    } catch (_) {
      // tidak ada yang tampil
    }
  }

  static Future<void> _stop() async {
    try {
      await platform.unschedule();
      await platform.cancelAll();
    } catch (_) {
      // plugin tidak tersedia
    }
  }

  /// Satu pemeriksaan (latar belakang). Mengembalikan jumlah notifikasi baru.
  /// Sesi yang ditolak server dibiarkan: ditangani saat app dibuka.
  static Future<int> check({ApiClient Function(Session)? client, DateTime? now}) async {
    final p = await SharedPreferences.getInstance();
    await p.reload(); // isolate latar belakang: nilai bisa diubah app sejak terakhir dibaca
    final session = await Session.load();
    final owner = session?.owner;
    if (session == null || owner == null || !(p.getBool(_enabledKey(owner)) ?? false)) return 0;
    if (session.role != 'member' && !session.hasMember) return 0;

    final List<MemberNotice> list;
    try {
      list = await (client?.call(session) ?? session.client()).myNotifications();
    } catch (_) {
      return 0; // tanpa koneksi/server sibuk: dicoba pada pemeriksaan berikutnya
    }

    // Bisa jadi sudah keluar, ganti akun, atau dimatikan selama menunggu server.
    await p.reload();
    if ((await Session.load())?.owner != owner || !(p.getBool(_enabledKey(owner)) ?? false)) return 0;

    final shown = p.getStringList(_shownKey(owner))?.toSet();
    final seen = await NoticeSeen.load();
    final t = now ?? DateTime.now();
    final fresh = shown == null
        ? const <MemberNotice>[] // pertama kali: jangan membanjiri dengan yang lama
        : list.where((n) => !shown.contains(n.id) && t.difference(n.at) < maxAge && (seen == null || n.at.isAfter(seen))).toList();
    // Simpan dulu: lebih baik satu terlewat (tetap ada di lonceng) daripada berulang tiap pemeriksaan.
    await p.setStringList(_shownKey(owner), [for (final n in list) n.id]);
    if (fresh.length > maxShown) {
      await platform.showSummary(fresh.length);
    } else {
      for (final n in fresh) {
        await platform.show(n);
      }
    }
    return fresh.length;
  }
}
