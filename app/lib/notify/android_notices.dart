import 'dart:ui';

import 'package:flutter/widgets.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:workmanager/workmanager.dart';

import '../api/api_client.dart';
import 'phone_notices.dart';

const _task = 'periksa-notifikasi';

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
    _ready = true;
  }

  // Isi disembunyikan di layar kunci yang diamankan: bisa memuat alasan izin atau tagihan.
  static const _details = NotificationDetails(
    android: AndroidNotificationDetails('anggota', 'Pemberitahuan anggota', channelDescription: 'Pengumuman, izin, tagihan, dan pengingat kegiatan', visibility: NotificationVisibility.private),
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
      frequency: const Duration(minutes: 30),
      constraints: Constraints(networkType: NetworkType.connected),
      existingWorkPolicy: ExistingPeriodicWorkPolicy.keep,
    );
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
