import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:kartuanggota/app/session.dart';
import 'package:kartuanggota/offline/offline_store.dart';
import 'package:kartuanggota/offline/photo_cache.dart';
import 'package:kartuanggota/offline/roster.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'flutter_test_config.dart';
import 'offline_test.dart' show at, budiCard, rosterJson;

Map<String, Object> legacy() => {'server_url': 'http://srv', 'token': 'lama', 'user_name': 'Sari', 'user_role': 'scanner', 'user_id': 1};
MemoryTokenStore get memory => Session.tokens as MemoryTokenStore;
Future<String?> plain() async => (await SharedPreferences.getInstance()).getString('token');

void main() {
  test('token baru hanya di penyimpanan terenkripsi', () async {
    SharedPreferences.setMockInitialValues({});
    await Session(baseUrl: 'http://srv', token: 'rahasia', userName: 'Sari', role: 'scanner').save();
    expect((memory.value, await plain()), ('rahasia', null));
    expect((await Session.load())!.token, 'rahasia');
  });

  test('sesi versi lama tetap masuk dan tokennya dipindahkan', () async {
    SharedPreferences.setMockInitialValues(legacy());
    expect((await Session.load())!.token, 'lama');
    expect((memory.value, await plain()), ('lama', null));
    expect((await Session.load())!.token, 'lama');
  });

  test('Keystore gagal saat pindah: sesi lama tetap masuk, dicoba lagi nanti', () async {
    SharedPreferences.setMockInitialValues(legacy());
    memory.broken = true;
    expect((await Session.load())!.token, 'lama');
    expect(await plain(), 'lama');
    memory.broken = false;
    await Session.load();
    expect((memory.value, await plain()), ('lama', null));
  });

  test('Keystore gagal saat masuk: token tidak pernah ditulis polos', () async {
    SharedPreferences.setMockInitialValues({});
    memory.broken = true;
    await Session(baseUrl: 'http://srv', token: 'rahasia', userName: 'Sari', role: 'scanner').save();
    expect(await plain(), isNull);
    expect(await Session.load(), isNull);
  });

  test('Keystore gagal saat ganti akun: token akun lama tidak terpakai', () async {
    SharedPreferences.setMockInitialValues({});
    await Session(baseUrl: 'http://srv', token: 'milik-a', userName: 'A', role: 'scanner').save();
    memory.broken = true;
    await Session.clear();
    await Session(baseUrl: 'http://srv', token: 'milik-b', userName: 'B', role: 'super_admin').save();
    memory.broken = false;
    expect(memory.value, 'milik-a');
    expect(await Session.load(), isNull);
  });

  test('keluar: tetap keluar walau token gagal dihapus dari Keystore', () async {
    SharedPreferences.setMockInitialValues({});
    await Session(baseUrl: 'http://srv', token: 'rahasia', userName: 'Sari', role: 'scanner').save();
    memory.broken = true;
    await Session.clear();
    memory.broken = false;
    expect(await Session.load(), isNull);
  });

  test('keluar menghapus data peserta semua akun, antrean & catatan penolakan tetap', () async {
    SharedPreferences.setMockInitialValues({});
    final store = await OfflineStore.open(owner: 'http://srv|1');
    await store.saveRoster(Roster.fromApi(rosterJson()));
    await store.enqueue(PendingScan(clientUuid: 'u', eventId: 7, payload: budiCard, source: 'nfc', scannedAt: at('01:10')));
    await store.addRejected([RejectedScan(clientUuid: 'r', eventId: 7, status: 'blocked', message: 'x', scannedAt: at('01:10'))]);
    await Session.clear();
    expect((store.loadRoster(7), store.pending().length, store.rejected().length), (null, 1, 1));
  });

  test('foto peserta dihapus; folder yang belum ada tidak masalah', () async {
    final dir = Directory.systemTemp.createTempSync('foto');
    addTearDown(() => dir.existsSync() ? dir.deleteSync(recursive: true) : null);
    File('${dir.path}/1_abcd.jpg').writeAsBytesSync([1]);
    await PhotoCache(dir).clear();
    expect(dir.existsSync(), isFalse);
    await PhotoCache(dir).clear();
  });
}
