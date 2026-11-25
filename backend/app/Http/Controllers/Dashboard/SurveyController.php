<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventSurvey;
use App\Services\SurveyService;
use Illuminate\Http\Request;

class SurveyController extends Controller
{
    public function __construct(private SurveyService $surveys) {}

    public function open(Request $r, Event $event)
    {
        $r->merge(['questions' => collect(preg_split('/\R/', (string) $r->input('questions')))->map(fn ($q) => trim($q))->filter()->values()->all()]);
        $d = $r->validate([
            'questions' => 'present|array|max:'.SurveyService::MAX_QUESTIONS, 'questions.*' => 'string|max:150|distinct',
            'days' => 'required|integer|min:1|max:'.SurveyService::MAX_DAYS,
        ], ['questions.max' => 'Maksimal '.SurveyService::MAX_QUESTIONS.' pertanyaan.', 'questions.*.max' => 'Tiap pertanyaan maksimal 150 karakter.', 'questions.*.distinct' => 'Ada pertanyaan yang sama.']);
        $this->surveys->open($r->user(), $event, $d['questions'], (int) $d['days']);

        return back()->with('status', 'Survei dibuka untuk peserta yang hadir.');
    }

    public function close(Request $r, EventSurvey $survey)
    {
        $this->surveys->close($r->user(), $survey);

        return back()->with('status', 'Survei ditutup.');
    }
}
