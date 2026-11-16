import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:kartuanggota/nfc/nfc_service.dart';
import 'package:nfc_manager/nfc_manager.dart';
import 'package:nfc_manager/nfc_manager_ios.dart' show NfcReaderSessionErrorIos;
// ignore: implementation_imports
import 'package:nfc_manager/src/nfc_manager_android/pigeon.g.dart' show TagPigeon;

/// NfcManager palsu: menyimpan callback dan bisa "menempelkan" tag.
class FakeNfc implements NfcManager {
  void Function(NfcTag)? onDiscovered;
  int starts = 0, stops = 0;

  @override
  Future<NfcAvailability> checkAvailability() async => NfcAvailability.enabled;

  @override
  Future<void> startSession({
    required Set<NfcPollingOption> pollingOptions,
    required void Function(NfcTag tag) onDiscovered,
    String? alertMessageIos,
    bool invalidateAfterFirstReadIos = true,
    void Function(NfcReaderSessionErrorIos)? onSessionErrorIos,
    bool noPlatformSoundsAndroid = false,
  }) async {
    starts++;
    this.onDiscovered = onDiscovered;
  }

  @override
  Future<void> stopSession({String? alertMessageIos, String? errorMessageIos}) async {
    stops++;
    onDiscovered = null;
  }

  /// Tempelkan tag seperti dari Android (UID 7 byte, NfcA + IsoDep seperti e-KTP).
  Future<void> tap() async {
    onDiscovered!(NfcTag(
      data: TagPigeon(
        handle: 'h',
        id: Uint8List.fromList([0x04, 0x11, 0x22, 0x33, 0x44, 0x55, 0x66]),
        techList: ['android.nfc.tech.NfcA', 'android.nfc.tech.IsoDep'],
      ),
    ));
    await Future<void>.delayed(Duration.zero);
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

void main() {
  test('NFC tetap dipegang: tap tanpa aksi ke onIdleTag, bukan dilepas', () async {
    final fake = FakeNfc();
    final svc = NfcService(fake);
    var idle = 0;
    svc.onIdleTag = (_) => idle++;

    await svc.start();
    await svc.start(); // idempoten
    expect(fake.starts, 1);

    await fake.tap();
    await fake.tap();
    expect(idle, 2);
    expect(fake.stops, 0); // sesi tidak ditutup setelah tap
  });

  test('aksi menunggu tap berikutnya, lalu sesi tetap aktif', () async {
    final fake = FakeNfc();
    final svc = NfcService(fake);
    var idle = 0;
    svc.onIdleTag = (_) => idle++;

    final pending = svc.detect();
    await Future<void>.delayed(Duration.zero);
    await fake.tap();
    final info = await pending;

    expect(info.techs, ['NfcA', 'IsoDep']);
    expect(info.uidMasked, startsWith('04••')); // UID disamarkan
    expect(idle, 0);
    expect(svc.isListening, isTrue);
    expect(fake.stops, 0);

    await fake.tap(); // tap berikutnya tanpa aksi
    expect(idle, 1);
  });

  test('aksi baru membatalkan aksi lama; stop melepas NFC', () async {
    final fake = FakeNfc();
    final svc = NfcService(fake);

    final first = svc.detect();
    await Future<void>.delayed(Duration.zero);
    final second = svc.detect();
    await expectLater(first, throwsA(isA<NfcException>()));

    await Future<void>.delayed(Duration.zero);
    await fake.tap();
    await second;

    final third = svc.detect();
    final thirdFails = expectLater(third, throwsA(isA<NfcException>()));
    await Future<void>.delayed(Duration.zero);
    await svc.stop();
    await thirdFails;
    expect(fake.stops, 1);
    expect(svc.isListening, isFalse);
  });
}
