import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:kartuanggota/api/api_client.dart';
import 'package:kartuanggota/feedback/scan_feedback.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  const hadir = ScanResult(status: 'ok', attendanceStatus: 'hadir', message: '');
  const telat = ScanResult(status: 'ok', attendanceStatus: 'telat', message: '');
  const dobel = ScanResult(status: 'duplicate', message: '');

  test('pemetaan bunyi per hasil scan', () {
    expect(soundFor(hadir), ScanSound.success);
    expect(soundFor(telat), ScanSound.warning);
    expect(soundFor(dobel), ScanSound.warning);
    for (final s in ['blocked', 'invalid', 'not_target', 'outside_window', 'clock_drift', 'error']) {
      expect(
        soundFor(ScanResult(status: s, message: '')),
        ScanSound.error,
        reason: s,
      );
    }
  });

  test('bunyi & getar diputar; bisu menghentikan bunyi tapi getar tetap; pilihan diingat', () async {
    SharedPreferences.setMockInitialValues({});
    final played = <ScanSound>[], buzzed = <String>[];
    ScanFeedback make() => ScanFeedback(player: (s) async => played.add(s), haptic: (r) async => buzzed.add(r.status));

    final f = make();
    await f.load();
    await f.play(hadir);
    expect(played, [ScanSound.success]);
    expect(buzzed, ['ok']);

    await f.setMuted(true);
    await f.play(telat);
    expect(played, [ScanSound.success]); // tidak berbunyi
    expect(buzzed, ['ok', 'ok']); // tetap bergetar

    final again = make();
    await again.load();
    expect(again.muted, isTrue); // diingat setelah app dibuka ulang
  });

  test('pemutar gagal tidak mengganggu scan', () async {
    SharedPreferences.setMockInitialValues({});
    final f = ScanFeedback(player: (_) async => throw Exception('audio rusak'), haptic: (_) async {});
    await f.play(hadir); // tidak melempar
  });

  test('berkas bunyi ada, WAV PCM mono, pendek', () {
    for (final name in ['success', 'warning', 'error']) {
      final b = File('assets/sounds/$name.wav').readAsBytesSync();
      final d = ByteData.sublistView(b);
      expect(String.fromCharCodes(b.sublist(0, 4)), 'RIFF', reason: name);
      expect(String.fromCharCodes(b.sublist(8, 12)), 'WAVE', reason: name);
      final channels = d.getUint16(22, Endian.little), rate = d.getUint32(24, Endian.little);
      final ms = (b.length - 44) / 2 / rate * 1000;
      expect(channels, 1, reason: name);
      expect(ms, inInclusiveRange(150, 400), reason: '$name $ms ms');
    }
  });
}
