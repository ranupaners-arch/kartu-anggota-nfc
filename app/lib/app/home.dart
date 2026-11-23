import 'package:material_ui/material_ui.dart';

import '../screens/events_screen.dart';
import '../screens/member/member_home_screen.dart';
import 'session.dart';

/// Layar utama sesuai peran: anggota (termasuk anggota yang sedang bertugas → + tab Tugas) → layar anggota;
/// akun petugas/pengurus tanpa data anggota (mis. HP sekretariat) → beranda petugas.
Widget homeFor(Session session) => session.role == 'member' || session.isMemberOnDuty ? MemberHomeScreen(session: session) : EventsScreen(session: session);
