import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:nfc_manager/nfc_manager.dart';
import 'package:nfc_manager/nfc_manager_android.dart';

import 'card_payload.dart';
import 'ntag.dart';

class TagInfo {
  const TagInfo({required this.uidMasked, required this.techList, this.model});

  final String uidMasked;
  final List<String> techList;
  final NtagModel? model;

  /// Ringkas nama teknologi: "android.nfc.tech.IsoDep" → "IsoDep".
  List<String> get techs => techList.map((t) => t.split('.').last).toList();
}

class ReadResult {
  const ReadResult({required this.info, this.text, this.payload});

  final TagInfo info;
  final String? text;
  final CardPayload? payload;
}

class WriteResult {
  const WriteResult({required this.model, required this.uidHex, required this.protectedNow});

  final NtagModel model;

  /// UID lengkap, dikirim ke `/api/cards/{id}/assign` sebagai `hw_uid`
  /// (server hanya menyimpan hash-nya).
  final String uidHex;
  final bool protectedNow;
}

class NfcException implements Exception {
  NfcException(this.message);
  final String message;
  @override
  String toString() => message;
}

/// Akses NFC (Android).
///
/// Selama [start] aktif (layar petugas terbuka), app memegang NFC dalam reader mode,
/// sehingga kartu yang ditempel tidak dilempar Android ke aplikasi lain (bank/e-money).
/// Operasi ([detect], [read], [write]) menunggu tap berikutnya; tap tanpa operasi
/// aktif diteruskan ke [onIdleTag].
class NfcService {
  NfcService([NfcManager? manager]) : _nfc = manager ?? NfcManager.instance;

  final NfcManager _nfc;
  bool _listening = false;
  Completer<Object?>? _pending;
  Future<Object?> Function(NfcTag tag)? _op;

  /// Dipanggil saat kartu ditempel tanpa operasi yang sedang menunggu.
  void Function(TagInfo info)? onIdleTag;

  bool get isListening => _listening;

  Future<NfcAvailability> availability() => _nfc.checkAvailability();

  /// Mulai memegang NFC. Aman dipanggil berulang.
  Future<void> start() async {
    if (_listening) return;
    await _nfc.startSession(pollingOptions: {NfcPollingOption.iso14443}, onDiscovered: _onTag);
    _listening = true;
  }

  /// Lepas NFC (mis. saat layar ditutup atau app ke background).
  Future<void> stop() async {
    _failPending(NfcException('Dibatalkan.'));
    if (!_listening) return;
    _listening = false;
    await _nfc.stopSession();
  }

  /// Batalkan operasi yang sedang menunggu tanpa melepas NFC.
  void cancel() => _failPending(NfcException('Dibatalkan.'));

  /// Deteksi saja: jenis teknologi dan UID tersamar. Tidak membaca isi chip.
  /// Aman untuk kartu apa pun (termasuk e-KTP) karena tidak mengirim perintah ke chip.
  Future<TagInfo> detect() => _once((tag) async => _info(tag));

  /// Baca kartu NTAG: teks NDEF dan payload kartu.
  Future<ReadResult> read({String prefix = CardPayload.defaultPrefix}) => _once((tag) async {
        final info = _info(tag);
        final ndef = NdefAndroid.from(tag);
        if (ndef == null) {
          throw NfcException('Kartu tidak berisi NDEF (${info.techs.join(', ')}). Bukan kartu anggota.');
        }
        final text = firstText(ndef.cachedNdefMessage ?? await ndef.getNdefMessage());
        return ReadResult(info: info, text: text, payload: text == null ? null : CardPayload.parse(text, prefix: prefix));
      });

  /// Tulis [payload] ke NTAG21x. Bila [protect], pasang password tulis (baca tetap bebas).
  /// Kartu yang sudah diproteksi akan diautentikasi dengan [pwdHex]/[packHex] lebih dulu.
  Future<WriteResult> write({
    required String payload,
    required String pwdHex,
    required String packHex,
    bool protect = true,
  }) {
    final pwd = hexToBytes(pwdHex, length: 4);
    final pack = hexToBytes(packHex, length: 2);

    return _once((tag) async {
      final ul = MifareUltralightAndroid.from(tag);
      if (ul == null) throw NfcException('Bukan kartu NTAG21x (MifareUltralight tidak tersedia).');

      final model = NtagModel.fromVersion(await ul.transceive(NtagCommand.getVersion));
      if (model == null) throw NfcException('Chip bukan NTAG213/215/216.');

      final cfg = await ul.readPages(pageOffset: model.cfg0); // CFG0, CFG1, PWD, PACK
      if (isProtected(cfg, model)) {
        final got = await ul.transceive(NtagCommand.pwdAuth(pwd));
        if (!listEquals(got.take(2).toList(), pack)) {
          throw NfcException('PACK tidak cocok: kartu bukan milik sistem ini atau password salah.');
        }
      }

      final pages = ndefTlvPages(textMessage(payload), model);
      for (var i = 0; i < pages.length; i++) {
        await ul.writePage(pageOffset: firstUserPage + i, data: pages[i]);
      }

      // Verifikasi: baca ulang dan bandingkan.
      for (var i = 0; i < pages.length; i += 4) {
        final read = await ul.readPages(pageOffset: firstUserPage + i);
        for (var j = 0; j < 4 && i + j < pages.length; j++) {
          if (!listEquals(read.sublist(j * 4, j * 4 + 4), pages[i + j])) {
            throw NfcException('Verifikasi gagal di halaman ${firstUserPage + i + j}. Ulangi penulisan.');
          }
        }
      }

      var protectedNow = isProtected(cfg, model);
      if (protect && !protectedNow) {
        // Urutan penting: PWD & PACK dulu, AUTH0 paling akhir.
        await ul.writePage(pageOffset: model.pwdPage, data: pwd);
        await ul.writePage(pageOffset: model.packPage, data: Uint8List.fromList([...pack, 0, 0]));
        await ul.writePage(pageOffset: model.cfg1, data: cfg1WriteProtectOnly(cfg.sublist(4, 8)));
        await ul.writePage(pageOffset: model.cfg0, data: cfg0WithAuth0(cfg.sublist(0, 4), firstUserPage));
        protectedNow = true;
      }

      return WriteResult(model: model, uidHex: bytesToHex(ul.tag.id), protectedNow: protectedNow);
    });
  }

  TagInfo _info(NfcTag tag) {
    final a = NfcTagAndroid.from(tag);
    return TagInfo(uidMasked: maskUid(a?.id ?? const []), techList: a?.techList ?? const []);
  }

  Future<void> _onTag(NfcTag tag) async {
    final op = _op, done = _pending;
    if (op == null || done == null) {
      onIdleTag?.call(_info(tag));
      return;
    }
    _op = null;
    _pending = null;
    try {
      done.complete(await op(tag));
    } catch (e) {
      done.completeError(e is NfcException ? e : NfcException('Gagal: $e'));
    }
  }

  void _failPending(NfcException e) {
    final done = _pending;
    _op = null;
    _pending = null;
    if (done != null && !done.isCompleted) done.completeError(e);
  }

  /// Tunggu satu tap untuk [op]. Operasi sebelumnya yang belum selesai dibatalkan.
  Future<T> _once<T>(Future<T> Function(NfcTag tag) op) async {
    await start();
    _failPending(NfcException('Digantikan operasi baru.'));
    final done = Completer<Object?>();
    _pending = done;
    _op = op;
    return (await done.future) as T;
  }
}
