<?php

namespace App\Http\Controllers\Leads;

use App\Enums\NoteVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leads\LeadNoteRequest;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Services\Leads\LeadNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LeadNoteController extends Controller
{
    public function __construct(private readonly LeadNoteService $notes) {}

    public function store(LeadNoteRequest $request, Lead $lead): RedirectResponse
    {
        $this->authorize('addNote', $lead);

        $this->notes->create($lead, $request->user(), $request->validated('note'), NoteVisibility::from($request->validated('visibility')));

        return back()->with('success', 'Note added.');
    }

    public function update(LeadNoteRequest $request, Lead $lead, LeadNote $note): RedirectResponse
    {
        $this->ensureBelongs($lead, $note);
        $this->authorize('update', $note);

        $this->notes->update($note, $request->user(), $request->validated('note'), NoteVisibility::from($request->validated('visibility')));

        return back()->with('success', 'Note updated.');
    }

    public function destroy(Request $request, Lead $lead, LeadNote $note): RedirectResponse
    {
        $this->ensureBelongs($lead, $note);
        $this->authorize('delete', $note);

        $this->notes->delete($note, $request->user());

        return back()->with('success', 'Note deleted.');
    }

    public function history(Request $request, Lead $lead, LeadNote $note): JsonResponse
    {
        $this->ensureBelongs($lead, $note);
        $this->authorize('view', $note);

        return response()->json([
            'data' => $note->histories()->with('editor:id,name')->limit(50)->get()->map(fn ($h) => [
                'id' => $h->id,
                'old_content' => $h->old_content,
                'new_content' => $h->new_content,
                'old_visibility' => $h->old_visibility,
                'new_visibility' => $h->new_visibility,
                'editor' => $h->editor?->name,
                'created_at' => $h->created_at?->toIso8601String(),
            ]),
        ]);
    }

    private function ensureBelongs(Lead $lead, LeadNote $note): void
    {
        abort_unless((int) $note->lead_id === $lead->id, 404);
    }
}
