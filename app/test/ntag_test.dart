import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:kartuanggota/nfc/ntag.dart';

void main() {
  test('kenali model dari GET_VERSION', () {
    Uint8List v(int size) => Uint8List.fromList([0x00, 0x04, 0x04, 0x02, 0x01, 0x00, size, 0x03]);
    expect(NtagModel.fromVersion(v(0x11)), NtagModel.ntag215);
    expect(NtagModel.fromVersion(v(0x0F)), NtagModel.ntag213);
    expect(NtagModel.fromVersion(v(0x13)), NtagModel.ntag216);
    expect(NtagModel.fromVersion(v(0x99)), isNull);
    expect(NtagModel.fromVersion(Uint8List.fromList([0, 0x05, 0x04, 0, 0, 0, 0x11, 0])), isNull); // bukan NXP
  });

  test('alamat konfigurasi NTAG215', () {
    const m = NtagModel.ntag215;
    expect([m.cfg0, m.cfg1, m.pwdPage, m.packPage], [0x83, 0x84, 0x85, 0x86]);
  });

  test('NDEF text round-trip dan TLV', () {
    const payload = 'card:v1:k1:01M7HC95QETAHJ4309PDSPG35N:64baa8aeec41fdf9dda2c8f9';
    final msg = textMessage(payload);
    expect(firstText(msg), payload);

    final pages = ndefTlvPages(msg, NtagModel.ntag215);
    final flat = pages.expand((p) => p).toList();
    expect(pages.every((p) => p.length == 4), isTrue);
    expect(flat[0], 0x03); // NDEF TLV
    expect(flat[1], msg.encode().length);
    expect(flat[2 + flat[1]], 0xFE); // terminator
  });

  test('tolak payload melebihi kapasitas', () {
    expect(() => ndefTlvPages(textMessage('x' * 200), NtagModel.ntag213), throwsStateError);
  });

  test('deteksi proteksi dan ubah konfigurasi', () {
    const m = NtagModel.ntag215;
    final factory = Uint8List.fromList([0x04, 0x00, 0x00, 0xFF, 0x80, 0x05, 0x00, 0x00, 0, 0, 0, 0, 0, 0, 0, 0]);
    expect(isProtected(factory, m), isFalse);

    final cfg0 = cfg0WithAuth0(factory.sublist(0, 4), firstUserPage);
    expect(cfg0, [0x04, 0x00, 0x00, 0x04]);
    expect(isProtected(Uint8List.fromList([...cfg0, ...factory.sublist(4)]), m), isTrue);

    expect(cfg1WriteProtectOnly(factory.sublist(4, 8))[0], 0x00); // PROT dimatikan: baca bebas
  });

  test('hex dan UID tersamar', () {
    expect(hexToBytes('a1b2c3d4', length: 4), [0xA1, 0xB2, 0xC3, 0xD4]);
    expect(() => hexToBytes('a1b2', length: 4), throwsFormatException);
    expect(maskUid([0x04, 0x11, 0x22, 0x33, 0x44, 0x55, 0x66]), startsWith('04••'));
    expect(maskUid([0x04, 0x11, 0x22, 0x33, 0x44, 0x55, 0x66]), isNot(contains('11')));
  });
}
