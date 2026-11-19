import 'package:audioplayers/audioplayers.dart';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_client.dart';

enum ScanSound { success, warning, error }

/// Bunyi untuk hasil scan: hadir → success; telat/sudah absen → warning; selain itu → error.
ScanSound soundFor(ScanResult r) {
  if (r.ok) return r.attendanceStatus == 'telat' ? ScanSound.warning : ScanSound.success;
  if (r.status == 'duplicate') return ScanSound.warning;
  return ScanSound.error;
}

typedef SoundPlayer = Future<void> Function(ScanSound sound);

/// Umpan balik hasil scan (PRD: visual + getar + suara). Bunyi bisa dibisukan; pilihan diingat.
class ScanFeedback {
  ScanFeedback({SoundPlayer? player, Future<void> Function(ScanResult)? haptic}) : _player = player ?? _AssetSoundPlayer().play, _haptic = haptic ?? _defaultHaptic;

  final SoundPlayer _player;
  final Future<void> Function(ScanResult) _haptic;
  bool _muted = false;

  static const _prefKey = 'scan_sound_muted';

  bool get muted => _muted;

  Future<void> load() async => _muted = (await SharedPreferences.getInstance()).getBool(_prefKey) ?? false;

  Future<void> setMuted(bool value) async {
    _muted = value;
    await (await SharedPreferences.getInstance()).setBool(_prefKey, value);
  }

  /// Getar selalu; bunyi bila tidak dibisukan. Kegagalan audio tidak boleh mengganggu scan.
  Future<void> play(ScanResult r) async {
    await _haptic(r);
    if (_muted) return;
    try {
      await _player(soundFor(r));
    } catch (_) {}
  }

  static Future<void> _defaultHaptic(ScanResult r) => r.ok ? HapticFeedback.heavyImpact() : HapticFeedback.vibrate();
}

/// Pemutar berjeda rendah (Android SoundPool) dengan satu pemain per bunyi.
class _AssetSoundPlayer {
  final _players = <ScanSound, AudioPlayer>{};

  static const _files = {ScanSound.success: 'sounds/success.wav', ScanSound.warning: 'sounds/warning.wav', ScanSound.error: 'sounds/error.wav'};

  Future<void> play(ScanSound sound) async {
    final p = _players.putIfAbsent(sound, AudioPlayer.new);
    await p.stop();
    await p.play(AssetSource(_files[sound]!), mode: PlayerMode.lowLatency);
  }
}
