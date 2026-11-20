import '../api/api_client.dart';
import '../nfc/nfc_service.dart';

enum IssueStep { preparing, waitingCard, linking, done }

/// Penulis kartu (bisa diganti di tes). Default: NfcService.write dengan proteksi password.
typedef CardWriter = Future<WriteResult> Function(CardInit init);

/// Anggota mendaftarkan kartu NFC sendiri (jatah 1×): siapkan → tulis & kunci → aktifkan.
class SelfCardIssuer {
  SelfCardIssuer(this._api, this._write);

  final ApiClient _api;
  final CardWriter _write;

  CardInit? _init;
  WriteResult? _written;

  bool get needsRelink => _written != null && _init != null;

  Future<MemberCardInfo> issue(void Function(IssueStep) onStep) async {
    onStep(IssueStep.preparing);
    _init = await _api.selfCardInit();
    _written = null;
    onStep(IssueStep.waitingCard);
    _written = await _write(_init!);
    return relink(onStep);
  }

  Future<MemberCardInfo> relink(void Function(IssueStep) onStep) async {
    final init = _init, written = _written;
    if (init == null || written == null) throw StateError('Belum ada kartu yang tertulis.');
    onStep(IssueStep.linking);
    final info = await _api.selfCardAssign(init.id, hwUid: written.uidHex);
    _init = null;
    _written = null;
    onStep(IssueStep.done);
    return info;
  }
}

/// Alur terbitkan kartu (US-1): init di server → tulis & kunci kartu → tautkan ke anggota.
/// Kartu baru tercatat aktif HANYA setelah penulisan berhasil; gagal di tengah = aman diulang.
class CardIssuer {
  CardIssuer(this._api, this._write);

  final ApiClient _api;
  final CardWriter _write;

  CardInit? _init;
  WriteResult? _written;

  /// Kartu sudah tertulis tetapi belum tertaut (mis. jaringan putus saat menautkan).
  bool get needsRelink => _written != null && _init != null;

  Future<void> issue(MemberHit member, void Function(IssueStep) onStep) async {
    onStep(IssueStep.preparing);
    _init = await _api.initCard();
    _written = null;

    onStep(IssueStep.waitingCard);
    _written = await _write(_init!); // melempar NfcException bila gagal → tidak ditautkan

    await relink(member, onStep);
  }

  /// Tautkan ulang kartu yang sudah tertulis (tanpa menulis ulang kartu).
  Future<void> relink(MemberHit member, void Function(IssueStep) onStep) async {
    final init = _init, written = _written;
    if (init == null || written == null) throw StateError('Belum ada kartu yang tertulis.');
    onStep(IssueStep.linking);
    await _api.assignCard(init.id, memberId: member.id, hwUid: written.uidHex);
    _init = null;
    _written = null;
    onStep(IssueStep.done);
  }
}
