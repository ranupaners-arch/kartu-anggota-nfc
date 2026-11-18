import '../api/api_client.dart';

/// Data offline satu kegiatan (dari GET /api/events/{id}/roster): jendela waktu + peserta dan
/// sidik jari (sha256) payload kartu mereka. Secret HMAC tidak pernah ada di perangkat.
class Roster {
  Roster({
    required this.eventId,
    required this.title,
    required this.opensAt,
    required this.lateAfter,
    required this.closesAt,
    required this.closed,
    required this.members,
    required this.generatedAt,
    this.sessions = const [],
  }) : _byFingerprint = {
          for (final m in members)
            for (final c in m.cards) c.fingerprint: (member: m, card: c),
        };

  final int eventId;
  final String title;
  final DateTime opensAt, lateAfter, closesAt;
  final bool closed;
  final List<RosterMember> members;
  final DateTime generatedAt;

  /// Sesi & jendelanya (kosong untuk roster dari server lama: pakai jendela kegiatan).
  final List<SessionInfo> sessions;
  final Map<String, ({RosterMember member, RosterCard card})> _byFingerprint;

  ({RosterMember member, RosterCard card})? byFingerprint(String sha256) => _byFingerprint[sha256];

  factory Roster.fromApi(Map<String, dynamic> j) {
    final e = j['event'] as Map<String, dynamic>;
    DateTime t(String k) => DateTime.parse(e[k] as String);
    return Roster(
      eventId: e['id'] as int,
      title: e['title'] as String,
      opensAt: t('checkin_opens_at'),
      lateAfter: t('late_after'),
      closesAt: t('checkin_closes_at'),
      closed: e['closed_at'] != null,
      members: [for (final m in j['members'] as List) RosterMember.fromJson(m as Map<String, dynamic>)],
      generatedAt: DateTime.parse(j['generated_at'] as String),
      sessions: SessionInfo.listFrom(e['sessions']),
    );
  }

  /// Bentuk penyimpanan lokal (sama dengan respons API agar satu parser).
  Map<String, dynamic> toApiJson() => {
        'event': {
          'id': eventId, 'title': title,
          'checkin_opens_at': opensAt.toUtc().toIso8601String(),
          'late_after': lateAfter.toUtc().toIso8601String(),
          'checkin_closes_at': closesAt.toUtc().toIso8601String(),
          'closed_at': closed ? generatedAt.toUtc().toIso8601String() : null,
          'sessions': [for (final s in sessions) s.toJson()],
        },
        'members': [for (final m in members) m.toJson()],
        'generated_at': generatedAt.toUtc().toIso8601String(),
      };
}

class RosterMember {
  const RosterMember({required this.id, required this.memberNo, required this.name, this.division, this.photoUrl, this.cards = const []});

  factory RosterMember.fromJson(Map<String, dynamic> j) => RosterMember(
        id: j['id'] as int,
        memberNo: j['member_no'] as String,
        name: j['name'] as String,
        division: j['division'] as String?,
        photoUrl: j['photo_url'] as String?,
        cards: [for (final c in (j['cards'] as List? ?? const [])) RosterCard.fromJson(c as Map<String, dynamic>)],
      );

  final int id;
  final String memberNo, name;
  final String? division, photoUrl;
  final List<RosterCard> cards;

  Map<String, dynamic> toJson() => {
        'id': id, 'member_no': memberNo, 'name': name, 'division': division, 'photo_url': photoUrl,
        'cards': [for (final c in cards) c.toJson()],
      };
}

class RosterCard {
  const RosterCard({required this.publicId, required this.status, required this.fingerprint});

  factory RosterCard.fromJson(Map<String, dynamic> j) =>
      RosterCard(publicId: j['public_id'] as String, status: j['status'] as String, fingerprint: j['payload_sha256'] as String);

  final String publicId, status, fingerprint;
  bool get active => status == 'active';

  Map<String, dynamic> toJson() => {'public_id': publicId, 'status': status, 'payload_sha256': fingerprint};
}
