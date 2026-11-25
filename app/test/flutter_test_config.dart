import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:kartuanggota/app/session.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/app/token_store.dart';
import 'package:kartuanggota/notify/phone_notices.dart';

/// Penyimpanan token di memori: plugin Keystore tidak tersedia di tes.
class MemoryTokenStore implements TokenStore {
  String? value;
  bool broken = false;

  void _check() {
    if (broken) throw Exception('Keystore rusak');
  }

  @override
  Future<String?> read() async {
    _check();
    return value;
  }

  @override
  Future<void> write(String token) async {
    _check();
    value = token;
  }

  @override
  Future<void> delete() async {
    _check();
    value = null;
  }
}

/// Notifikasi HP palsu: mencatat panggilan, tanpa plugin Android.
class FakeNotices implements NoticePlatform {
  bool allow = true, scheduled = false, broken = false;
  int cancelled = 0, asked = 0;
  final shown = <String>[];
  void Function()? onTap;

  void _check() {
    if (broken) throw Exception('plugin tidak tersedia');
  }

  @override
  Future<bool> requestPermission() async {
    asked++;
    return allow;
  }

  @override
  Future<void> schedule() async {
    _check();
    scheduled = true;
  }

  @override
  Future<void> unschedule() async {
    _check();
    scheduled = false;
  }

  @override
  Future<void> show(MemberNotice notice) async => shown.add(notice.id);
  @override
  Future<void> showSummary(int count) async => shown.add('ringkasan:$count');
  @override
  Future<void> cancelAll() async => cancelled++;
  @override
  Future<void> listen(void Function() onTap) async => this.onTap = onTap;
}

Future<void> testExecutable(FutureOr<void> Function() testMain) async {
  setUp(() {
    Session.tokens = MemoryTokenStore();
    PhoneNotices.platform = FakeNotices();
  });
  await testMain();
}
