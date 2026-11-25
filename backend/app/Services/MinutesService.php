<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventDocument;
use App\Models\EventMinutes;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Notulen & dokumen kegiatan. Mengubah: pengurus yang boleh mengelola kegiatannya. Membaca: peserta kegiatan
 * (target atau yang tercatat hadir), hanya notulen terbit dan dokumen yang dibuka untuk peserta.
 */
class MinutesService
{
    public const MAX_DOCUMENTS = 20;

    public const FILE_RULES = 'file|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,ppt,pptx|extensions:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,ppt,pptx|max:5120';

    /** Daftar anggota dibatasi kegiatan terbaru. */
    private const LIST_LIMIT = 100;

    public function __construct(private EventService $events) {}

    /** $version = versi yang dibuka pengurus (0 = belum ada). */
    public function save(User $by, Event $event, string $content, int $version): EventMinutes
    {
        $this->events->authorize($by, $event);

        return DB::transaction(function () use ($by, $event, $content, $version) {
            $this->lock($event);
            $m = EventMinutes::firstOrNew(['event_id' => $event->id]);
            if (($m->exists ? $m->version : 0) !== $version) {
                throw ValidationException::withMessages(['content' => 'Notulen sudah diubah '.($m->editor?->name ?? 'pengurus lain').'. Salin tulisan Anda, muat ulang halaman, lalu gabungkan.']);
            }
            $m->fill(['content' => $content, 'version' => $version + 1, 'updated_by' => $by->id])->save();
            AuditLogger::log('minutes.saved', 'event', $event->id, ['version' => $m->version, 'published' => $m->published_at !== null], $by->id);

            return $m;
        });
    }

    public function setPublished(User $by, Event $event, bool $published): void
    {
        $this->events->authorize($by, $event);
        $m = EventMinutes::where('event_id', $event->id)->first();
        abort_unless($m, 422, 'Simpan notulen dulu.');
        if (($m->published_at !== null) === $published) {
            return;
        }
        $m->update(['published_at' => $published ? now() : null]);
        AuditLogger::log($published ? 'minutes.published' : 'minutes.unpublished', 'event', $event->id, [], $by->id);
    }

    public function addDocument(User $by, Event $event, string $title, bool $forMembers, UploadedFile $file): EventDocument
    {
        $this->events->authorize($by, $event);
        $path = $file->store("event-documents/{$event->organization_id}", 'local');

        try {
            return DB::transaction(function () use ($by, $event, $title, $forMembers, $file, $path) {
                $this->lock($event);
                if (EventDocument::where('event_id', $event->id)->count() >= self::MAX_DOCUMENTS) {
                    throw ValidationException::withMessages(['file' => 'Maksimal '.self::MAX_DOCUMENTS.' dokumen per kegiatan.']);
                }
                $d = EventDocument::create(['event_id' => $event->id, 'title' => $title, 'path' => $path, 'for_members' => $forMembers,
                    'extension' => strtolower($file->getClientOriginalExtension()), 'size' => $file->getSize(), 'uploaded_by' => $by->id]);
                AuditLogger::log('document.added', 'event', $event->id, ['document_id' => $d->id, 'title' => $title, 'for_members' => $forMembers], $by->id);

                return $d;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path); // gagal: berkas tidak boleh tertinggal
            throw $e;
        }
    }

    public function setVisibility(User $by, EventDocument $document, bool $forMembers): void
    {
        $this->events->authorize($by, $document->event);
        $document->update(['for_members' => $forMembers]);
        AuditLogger::log('document.visibility', 'event', $document->event_id, ['document_id' => $document->id, 'for_members' => $forMembers], $by->id);
    }

    public function deleteDocument(User $by, EventDocument $document): void
    {
        $this->events->authorize($by, $document->event);
        $document->delete();
        Storage::disk('local')->delete($document->path);
        AuditLogger::log('document.deleted', 'event', $document->event_id, ['document_id' => $document->id, 'title' => $document->title], $by->id);
    }

    public function download(EventDocument $document): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $this->fileName($document), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function fileName(EventDocument $document): string
    {
        return (Str::slug($document->title) ?: 'dokumen').'.'.$document->extension;
    }

    /** Tab di halaman kegiatan (pengurus). */
    public function tab(Event $event): array
    {
        $m = EventMinutes::with('editor:id,name')->where('event_id', $event->id)->first();

        return [
            'minutes' => ['content' => $m->content ?? '', 'version' => $m->version ?? 0, 'published_at' => $m?->published_at, 'updated_at' => $m?->updated_at, 'editor' => $m?->editor?->name],
            'documents' => EventDocument::with('uploader:id,name')->where('event_id', $event->id)->orderBy('id')->get()
                ->map(fn (EventDocument $d) => $d->only('id', 'title', 'extension', 'size', 'for_members', 'created_at') + ['by' => $d->uploader?->name]),
            'max' => self::MAX_DOCUMENTS,
        ];
    }

    /** Kegiatan yang notulen/dokumennya boleh dibaca anggota, terbaru dulu. */
    public function listFor(int $memberId): Collection
    {
        $events = Event::where(fn ($q) => $q
            ->whereIn('id', EventMinutes::whereNotNull('published_at')->select('event_id'))
            ->orWhereIn('id', EventDocument::where('for_members', true)->select('event_id')))
            ->latest('starts_at')->limit(self::LIST_LIMIT)->get()->filter(fn (Event $e) => $this->canRead($e, $memberId))->values();
        $minutes = EventMinutes::whereIn('event_id', $events->pluck('id'))->whereNotNull('published_at')->get()->keyBy('event_id');
        $documents = EventDocument::whereIn('event_id', $events->pluck('id'))->where('for_members', true)->orderBy('id')->get()->groupBy('event_id');

        return $events->map(fn (Event $e) => [
            'event_id' => $e->id, 'title' => $e->title, 'date' => $e->starts_at, 'content' => $minutes[$e->id]->content ?? null,
            'updated_at' => $minutes[$e->id]->updated_at ?? null,
            'documents' => ($documents[$e->id] ?? collect())->map(fn (EventDocument $d) => $d->only('id', 'title', 'size') + ['file_name' => $this->fileName($d)])->values(),
        ]);
    }

    public function memberDownload(EventDocument $document, int $memberId): StreamedResponse
    {
        abort_unless($document->for_members && $this->canRead($document->event, $memberId), 404);

        return $this->download($document);
    }

    public function canRead(Event $event, int $memberId): bool
    {
        return $event->targetMembers()->whereKey($memberId)->exists()
            || Attendance::where('event_id', $event->id)->where('member_id', $memberId)->whereNull('voided_at')->whereIn('status', ['hadir', 'telat'])->exists();
    }

    private function lock(Event $event): void
    {
        Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
    }
}
