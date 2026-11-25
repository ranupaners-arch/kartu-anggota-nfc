import 'dart:ui';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:timezone/timezone.dart' as tz;
import 'package:workmanager/workmanager.dart';

import '../api/api_client.dart';
import 'phone_notices.dart';

const _task = 'periksa-notifikasi';

/// Hanya build debug: periksa tiap menit, supaya bisa diuji tanpa menunggu.
Future<void> _quickCheck() => Workmanager().registerOneOffTask(
  'uji-cepat-${DateTime.now().millisecondsSinceEpoch ~/ 60000}',
  _task,
  initialDelay: const Duration(minutes: 1),
  constraints: Constraints(networkType: NetworkType.notRequired),
  existingWorkPolicy: ExistingWorkPolicy.keep,
);

/// Titik masuk isolate latar belakang (WorkManager).
@pragma('vm:entry-point')
void noticeWorker() {
  Workmanager().executeTask((task, _) async {
    WidgetsFlutterBinding.ensureInitialized();
    DartPluginRegistrant.ensureInitialized();
    try {
      await PhoneNotices.check();
    } catch (_) {
      // jangan diulang cepat-cepat; pemeriksaan berikutnya tetap terjadwal
    }
    if (kDebugMode && await PhoneNotices.active()) await _quickCheck();
    return true;
  });
}

class AndroidNotices implements NoticePlatform {
  const AndroidNotices();

  static final _plugin = FlutterLocalNotificationsPlugin();
  static bool _ready = false;
  static bool _launchHandled = false;
  static void Function()? _onTap;

  Future<void> _init() async {
    if (_ready) return;
    await _plugin.initialize(
      settings: const InitializationSettings(android: AndroidInitializationSettings('ic_notice')),
      onDidReceiveNotificationResponse: (_) => _onTap?.call(),
    );
    await _plugin.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()?.deleteNotificationChannel(channelId: _oldChannel);
    _ready = true;
  }

  // Prioritas tinggi: muncul sebagai kartu di atas layar. Prioritas saluran tidak bisa diubah setelah dibuat,
  // jadi saluran lama ('anggota') diganti. Isi disembunyikan di layar kunci yang diamankan.
  static const _oldChannel = 'anggota';
  static const _details = NotificationDetails(
    android: AndroidNotificationDetails(
      'anggota_penting',
      'Pemberitahuan anggota',
      channelDescription: 'Pengumuman, izin, tagihan, dan pengingat kegiatan',
      importance: Importance.high,
      priority: Priority.high,
      visibility: NotificationVisibility.private,
    ),
  );

  /// Id tetap per notifikasi (FNV-1a 31 bit): notifikasi yang sama menimpa, bukan menumpuk.
  static int idOf(String key) {
    var h = 0x811c9dc5;
    for (final c in key.codeUnits) {
      h = ((h ^ c) * 0x01000193) & 0xffffffff;
    }
    return h & 0x7fffffff;
  }

  @override
  Future<bool> requestPermission() async {
    await _init();
    return await _plugin.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()?.requestNotificationsPermission() ?? false;
  }

  @override
  Future<void> schedule() async {
    await Workmanager().initialize(noticeWorker);
    await Workmanager().registerPeriodicTask(
      _task,
      _task,
      frequency: const Duration(minutes: 15), // paling rapat yang diizinkan Android
      // Tanpa syarat jaringan: di Samsung A36 pekerjaan bersyarat jaringan sempat tertahan beberapa menit walau
      // internet aktif. Tanpa koneksi, pemeriksaan cukup gagal diam-diam dan diulang pada jadwal berikutnya.
      constraints: Constraints(networkType: NetworkType.notRequired),
      existingWorkPolicy: ExistingPeriodicWorkPolicy.update, // jadwal lama (30 menit) ikut diganti
    );
    if (kDebugMode) await _quickCheck();
  }

  @override
  Future<void> unschedule() => Workmanager().cancelByUniqueName(_task);

  @override
  Future<void> show(MemberNotice notice) async {
    await _init();
    await _plugin.show(id: idOf(notice.id), title: notice.title, body: notice.body, notificationDetails: _details, payload: notice.id);
  }

  @override
  Future<void> showSummary(int count) async {
    await _init();
    await _plugin.show(id: idOf('ringkasan'), title: '$count pemberitahuan baru', body: 'Ketuk untuk membuka.', notificationDetails: _details, payload: 'ringkasan');
  }

  @override
  Future<void> remind(Reminder r) async {
    await _init();
    await _plugin.zonedSchedule(
      id: idOf(r.key),
      title: r.title,
      body: r.body,
      payload: r.key,
      scheduledDate: tz.TZDateTime.from(r.at.toUtc(), tz.UTC),
      notificationDetails: _details,
      androidScheduleMode: AndroidScheduleMode.inexactAllowWhileIdle, // tanpa izin alarm tepat
    );
  }

  @override
  Future<void> cancelReminder(String key) async {
    await _init();
    await _plugin.cancel(id: idOf(key));
  }

  /// Hanya yang sudah tampil: `cancelAll` milik plugin juga menghapus alarm yang belum berbunyi.
  @override
  Future<void> cancelShown() async {
    await _init();
    for (final n in await _plugin.getActiveNotifications()) {
      if (n.id != null) await _plugin.cancel(id: n.id!);
    }
  }

  @override
  Future<void> cancelAll() async {
    await _init();
    await _plugin.cancelAll();
  }

  @override
  Future<void> listen(void Function() onTap) async {
    _onTap = onTap;
    await _init();
    if (_launchHandled) return;
    _launchHandled = true;
    if ((await _plugin.getNotificationAppLaunchDetails())?.didNotificationLaunchApp ?? false) onTap();
  }
}
