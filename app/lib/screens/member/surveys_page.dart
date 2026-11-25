import 'package:material_ui/material_ui.dart';
import 'package:shadcn_ui/shadcn_ui.dart';

import '../../api/api_client.dart';
import '../../app/format.dart';
import '../../widgets/ui.dart';

/// Survei kegiatan yang belum diisi (tanpa nama, sekali isi).
class SurveysPage extends StatefulWidget {
  const SurveysPage({super.key, required this.api});

  final ApiClient api;

  @override
  State<SurveysPage> createState() => _SurveysPageState();
}

class _SurveysPageState extends State<SurveysPage> {
  List<MemberSurvey>? _items;
  String? _error;
  bool _sent = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    List<MemberSurvey>? items;
    String? error;
    try {
      items = await widget.api.surveys();
    } on ApiException catch (e) {
      error = e.status == 404 ? 'Server organisasi Anda belum mendukung survei.' : e.message;
    } catch (_) {
      error = 'Server tidak terjangkau. Tarik untuk mencoba lagi.';
    }
    if (!mounted) return;
    setState(() {
      _items = items ?? _items;
      _error = error;
    });
  }

  void _done(MemberSurvey s) {
    setState(() {
      _items = [
        for (final x in _items ?? const <MemberSurvey>[])
          if (x.id != s.id) x,
      ];
      _sent = true;
    });
  }

  @override
  Widget build(BuildContext context) {
    final items = _items;
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          if (_error != null) ...[ErrorBanner(_error!), const SizedBox(height: 12)],
          if (_sent) ...[const ShadAlert(icon: Icon(LucideIcons.check), title: Text('Terima kasih'), description: Text('Jawaban Anda tersimpan tanpa nama.')), const SizedBox(height: 12)],
          if (items == null && _error == null)
            const Padding(
              padding: EdgeInsets.all(32),
              child: Center(child: CircularProgressIndicator()),
            ),
          if (items != null && items.isEmpty) Text('Tidak ada survei yang perlu diisi.', style: ShadTheme.of(context).textTheme.muted),
          for (final s in items ?? const <MemberSurvey>[])
            Padding(
              key: ValueKey(s.id),
              padding: const EdgeInsets.only(bottom: 12),
              child: _SurveyCard(api: widget.api, survey: s, onDone: () => _done(s)),
            ),
        ],
      ),
    );
  }
}

class _SurveyCard extends StatefulWidget {
  const _SurveyCard({required this.api, required this.survey, required this.onDone});

  final ApiClient api;
  final MemberSurvey survey;
  final VoidCallback onDone;

  @override
  State<_SurveyCard> createState() => _SurveyCardState();
}

class _SurveyCardState extends State<_SurveyCard> {
  // Indeks 0 = kepuasan keseluruhan, sisanya pertanyaan tambahan.
  late final List<int> _scores = List.filled(widget.survey.questions.length + 1, 0);
  final _comment = TextEditingController();
  bool _sending = false;
  String? _error;

  @override
  void dispose() {
    _comment.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    setState(() {
      _sending = true;
      _error = null;
    });
    final text = _comment.text.trim();
    try {
      await widget.api.answerSurvey(widget.survey.id, rating: _scores.first, answers: _scores.sublist(1), comment: text.isEmpty ? null : text);
      if (mounted) widget.onDone();
      return;
    } on ApiException catch (e) {
      _error = e.message;
    } catch (_) {
      _error = 'Server tidak terjangkau. Jawaban belum terkirim.';
    }
    if (mounted) setState(() => _sending = false);
  }

  Widget _rating(int i, String label) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    spacing: 4,
    children: [
      Text(label, style: ShadTheme.of(context).textTheme.small),
      Wrap(
        children: [
          for (var n = 1; n <= 5; n++)
            Semantics(
              label: '$label: $n dari 5',
              button: true,
              selected: _scores[i] == n,
              excludeSemantics: true,
              onTap: () => setState(() => _scores[i] = n),
              child: IconButton(
                key: ValueKey('nilai-$i-$n'),
                onPressed: _sending ? null : () => setState(() => _scores[i] = n),
                icon: Icon(_scores[i] >= n ? Icons.star : Icons.star_border, color: ShadTheme.of(context).colorScheme.primary),
              ),
            ),
        ],
      ),
    ],
  );

  @override
  Widget build(BuildContext context) {
    final s = widget.survey;
    return SectionCard(
      title: s.event,
      icon: LucideIcons.star,
      description: 'Tanpa nama, sampai ${fmtDateTime(s.closesAt)}. 1 = sangat kurang, 5 = sangat baik.',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        spacing: 12,
        children: [
          _rating(0, 'Kepuasan keseluruhan'),
          for (var i = 0; i < s.questions.length; i++) _rating(i + 1, s.questions[i]),
          ShadInput(controller: _comment, enabled: !_sending, maxLength: 1000, minLines: 2, maxLines: 5, placeholder: const Text('Saran (opsional, jangan tulis nama)')),
          if (_error != null) ErrorBanner(_error!),
          FlexButton(label: _sending ? 'Mengirim…' : 'Kirim jawaban', icon: LucideIcons.send, enabled: !_sending && _scores.every((x) => x > 0), onPressed: _send),
        ],
      ),
    );
  }
}
