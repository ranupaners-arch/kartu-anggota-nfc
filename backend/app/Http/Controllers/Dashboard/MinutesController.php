<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventDocument;
use App\Services\EventService;
use App\Services\MinutesService;
use Illuminate\Http\Request;

class MinutesController extends Controller
{
    public function __construct(private MinutesService $minutes) {}

    public function save(Request $r, Event $event)
    {
        $d = $r->validate(['content' => 'required|string|max:20000', 'version' => 'required|integer|min:0']);
        $m = $this->minutes->save($r->user(), $event, $d['content'], (int) $d['version']);

        return back()->with('status', $m->published_at ? 'Notulen diperbarui. Peserta langsung melihat perubahannya.' : 'Draf notulen disimpan.');
    }

    public function publish(Request $r, Event $event)
    {
        $d = $r->validate(['published' => 'required|boolean']);
        $this->minutes->setPublished($r->user(), $event, (bool) $d['published']);

        return back()->with('status', $d['published'] ? 'Notulen diterbitkan untuk peserta.' : 'Notulen ditarik menjadi draf.');
    }

    public function storeDocument(Request $r, Event $event)
    {
        $d = $r->validate(['title' => 'required|string|max:150', 'for_members' => 'required|boolean', 'file' => 'required|'.MinutesService::FILE_RULES]);
        $this->minutes->addDocument($r->user(), $event, $d['title'], (bool) $d['for_members'], $r->file('file'));

        return back()->with('status', 'Dokumen ditambahkan.');
    }

    public function updateDocument(Request $r, EventDocument $document)
    {
        $d = $r->validate(['for_members' => 'required|boolean']);
        $this->minutes->setVisibility($r->user(), $document, (bool) $d['for_members']);

        return back()->with('status', $d['for_members'] ? 'Dokumen dibuka untuk peserta.' : 'Dokumen hanya untuk pengurus.');
    }

    public function destroyDocument(Request $r, EventDocument $document)
    {
        $this->minutes->deleteDocument($r->user(), $document);

        return back()->with('status', 'Dokumen dihapus.');
    }

    public function download(Request $r, EventDocument $document, EventService $events)
    {
        $events->authorize($r->user(), $document->event);

        return $this->minutes->download($document);
    }
}
