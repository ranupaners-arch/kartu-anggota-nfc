import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_client.dart';
import '../app/format.dart';
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
  Future<void> remind(Reminder reminder);
  Future<void> cancelReminder(String key);

  /// Bersihkan yang tampil; alarm yang belum berbunyi tetap.
  Future<void> cancelShown();
  Future<void> cancelAll();

  /// Panggil [onTap] saat notifikasi diketuk, termasuk ketukan yang baru saja membuka app.
  Future<void> listen(void Function() onTap);
}

/// Pengingat kegiatan yang dipasang sebagai alarm di HP: berbunyi tanpa memeriksa server.
@immutable
class Reminder {
  const Reminder({required this.key, required this.title, required this.body, required this.at});

  /// `reminder:<id>` (24 jam sebelum; sama dengan id pengingat dari server) atau `soon:<id>` (1 jam sebelum).
  final String key, title, body;
  final DateTime at;

  /// Berubah bila jadwal, judul, atau lokasinya berubah.
  String get stamp => '$key|${at.millisecondsSinceEpoch}|$title|$body';
}

/// Pengingat untuk kegiatan mendatang yang belum dihadiri dan belum diajukan izin.
List<Reminder> plannedReminders(List<MemberEvent> events, DateTime now) {
  final upcoming = events.where((e) => !e.closed && e.myStatus == null && e.excuseStatus == null && e.startsAt.isAfter(now)).toList()..sort((a, b) => a.startsAt.compareTo(b.startsAt));
  return [
    for (final e in upcoming.take(20))
      for (final (key, title, before) in [('reminder', 'Pengingat', const Duration(hours: 24)), ('soon', 'Satu jam lagi', const Duration(hours: 1))])
        if (e.startsAt.subtract(before).isAfter(now))
          Reminder(key: '$key:${e.id}', title: '$title: ${e.title}', body: [fmtDateTime(e.startsAt), ?e.location, 'bawa kartu anggota'].join(' · '), at: e.startsAt.subtract(before)),
  ];
}

/// Notifikasi di bilah status HP: server diperiksa berkala di latar belakang, lalu yang baru ditampilkan
/// sebagai notifikasi lokal. Tanpa Firebase, jadi berlaku untuk server organisasi mana pun; bisa terlambat
/// (Android menentukan waktunya). Bawaan menyala; izinnya diminta sekali dan diatur di setelan Android.
abstract final class PhoneNotices {
  static NoticePlatform platform = const AndroidNotices();

  /// Lebih dari ini diringkas menjadi satu notifikasi.
  static const maxShown = 4;
  static const maxAge = Duration(days: 3);

  static const _askedKey = 'notif_izin_diminta';
  static String _shownKey(String owner) => 'notif_shown@$owner';
  static String _alarmKey(String owner) => 'notif_alarm@$owner';

  /// Layar anggota dibuka: pasang penangan ketukan, minta izin (sekali), dan jadwalkan pemeriksaan.
  static Future<void> attach(void Function() onTap) async {
    try {
      await platform.listen(onTap);
      await platform.schedule(); // juga tanpa izin: begitu diizinkan di setelan Android, langsung berlaku
      final p = await SharedPreferences.getInstance();
      if (!(p.getBool(_askedKey) ?? false)) {
        await p.setBool(_askedKey, true);
        await platform.requestPermission();
      }
    } catch (_) {
      // plugin tidak tersedia: app tetap jalan tanpa notifikasi HP
    }
  }

  /// Samakan alarm di HP dengan daftar kegiatan terbaru (yang berubah dipasang ulang, yang hilang dibatalkan).
  static Future<void> syncReminders(String owner, List<MemberEvent> events, {DateTime? now}) async {
    try {
      final p = await SharedPreferences.getInstance();
      final t = now ?? DateTime.now();
      final planned = plannedReminders(events, t);
      final keys = {for (final r in planned) r.key}, stamps = {for (final r in planned) r.stamp};
      final upcoming = {
        for (final e in events)
          if (e.startsAt.isAfter(t)) e.id,
      };
      final old = p.getStringList(_alarmKey(owner)) ?? const <String>[];
      final kept = <String>[];
      for (final s in old) {
        final part = s.split('|');
        final fired = DateTime.fromMillisecondsSinceEpoch(int.parse(part[1])).isBefore(t);
        final id = int.parse(part.first.split(':').last);
        if (fired && upcoming.contains(id)) {
          if (!keys.contains(part.first)) kept.add(s); // sudah berbunyi: jangan diulang oleh pemeriksaan server
        } else if (!fired && !keys.contains(part.first)) {
          await platform.cancelReminder(part.first);
        }
      }
      for (final r in planned) {
        if (!old.contains(r.stamp)) await platform.remind(r);
      }
      await p.setStringList(_alarmKey(owner), [...kept, ...stamps]);
    } catch (_) {
      // plugin tidak tersedia
    }
  }

  static Future<Set<String>> _alarmed(SharedPreferences p, String owner) async => {for (final s in p.getStringList(_alarmKey(owner)) ?? const <String>[]) s.split('|').first};

  /// Ada akun anggota yang perlu diperiksa.
  static Future<bool> active() async {
    final s = await Session.load();
    return s?.owner != null && (s!.role == 'member' || s.hasMember);
  }

  /// Keluar akun: berhenti memeriksa & hapus notifikasi yang masih tampil.
  static Future<void> signedOut(String? owner) async {
    if (owner != null) {
      final p = await SharedPreferences.getInstance();
      await p.remove(_shownKey(owner));
      await p.remove(_alarmKey(owner));
    }
    await _stop();
  }

  static Future<void> clearShown() async {
    try {
      await platform.cancelShown();
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
    if (session == null || owner == null) return 0;
    if (session.role != 'member' && !session.hasMember) return 0;

    final api = client?.call(session) ?? session.client();
    final List<MemberNotice> list;
    List<MemberEvent>? events;
    try {
      list = await api.myNotifications();
      events = await api.myEvents().then<List<MemberEvent>?>((e) => e).catchError((Object _) => null);
    } catch (_) {
      return 0; // tanpa koneksi/server sibuk: dicoba pada pemeriksaan berikutnya
    }

    // Bisa jadi sudah keluar, atau ganti akun selama menunggu server.
    await p.reload();
    if ((await Session.load())?.owner != owner) return 0;

    if (events != null) await syncReminders(owner, events, now: now);
    final alarmed = await _alarmed(p, owner); // sudah/akan diingatkan alarm HP
    final shown = p.getStringList(_shownKey(owner))?.toSet();
    final seen = await NoticeSeen.load();
    final t = now ?? DateTime.now();
    final fresh = shown == null
        ? const <MemberNotice>[] // pertama kali: jangan membanjiri dengan yang lama
        : list.where((n) => !shown.contains(n.id) && !alarmed.contains(n.id) && t.difference(n.at) < maxAge && (seen == null || n.at.isAfter(seen))).toList();
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
