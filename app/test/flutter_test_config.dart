import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:kartuanggota/app/session.dart';
import 'package:kartuanggota/app/token_store.dart';

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

Future<void> testExecutable(FutureOr<void> Function() testMain) async {
  setUp(() => Session.tokens = MemoryTokenStore());
  await testMain();
}
