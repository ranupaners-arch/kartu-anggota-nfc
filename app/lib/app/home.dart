import 'package:material_ui/material_ui.dart';

import '../screens/events_screen.dart';
import '../screens/member/member_home_screen.dart';
import 'session.dart';

/// Layar utama sesuai peran: anggota → layar anggota; petugas & pengurus → kegiatan.
Widget homeFor(Session session) => session.role == 'member' ? MemberHomeScreen(session: session) : EventsScreen(session: session);
