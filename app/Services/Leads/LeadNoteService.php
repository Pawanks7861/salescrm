<?php

namespace App\Services\Leads;

use App\Enums\AuditAction;
use App\Enums\NoteVisibility;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\LeadNoteHistory;
use App\Models\User;
use App\Services\ActivityService;
use App\Services\AuditService;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * Note visibility:
 *  - private    → author, or holders of note.view_private
 *  - team       → anyone who can view the lead
 *  - management → author, or holders of note.view_management
 * Note content is never written to activities or audit logs (only ids/visibility).
 */
class LeadNoteService
{
    public function __construct(
        private readonly ActivityService $activities,
        private readonly AuditService $audit,
    ) {}

    /** Constrains a notes query to the ones the user may read. */
    public function scopeVisible(Builder|HasMany $query, User $user): Builder|HasMany
    {
        return $query->where(function ($q) use ($user) {
            $q->where('visibility', NoteVisibility::Team->value)
                ->orWhere('created_by', $user->id);

            if ($user->hasPermission(Permissions::NOTE_VIEW_PRIVATE)) {
                $q->orWhere('visibility', NoteVisibility::Private->value);
            }
            if ($user->hasPermission(Permissions::NOTE_VIEW_MANAGEMENT)) {
                $q->orWhere('visibility', NoteVisibility::Management->value);
            }
        });
    }

    /** Hides timeline entries about notes the user may not read (their existence is private too). */
    public function scopeTimeline(Builder|MorphMany $activities, Lead $lead, User $user): Builder|MorphMany
    {
        $readable = $this->scopeVisible(LeadNote::withTrashed()->where('lead_id', $lead->id), $user)->pluck('id');
        $hidden = LeadNote::withTrashed()->where('lead_id', $lead->id)->whereNotIn('id', $readable)->pluck('id')->all();

        if ($hidden === []) {
            return $activities;
        }

        return $activities->where(fn ($q) => $q
            ->whereNotIn('type', [ActivityService::NOTE_ADDED, ActivityService::NOTE_EDITED, ActivityService::NOTE_DELETED])
            ->orWhereNotIn('properties->note_id', $hidden));
    }

    public function canRead(User $user, LeadNote $note): bool
    {
        if ((int) $note->created_by === $user->id) {
            return true;
        }

        return match ($note->visibility) {
            NoteVisibility::Team => true,
            NoteVisibility::Private => $user->hasPermission(Permissions::NOTE_VIEW_PRIVATE),
            NoteVisibility::Management => $user->hasPermission(Permissions::NOTE_VIEW_MANAGEMENT),
        };
    }

    /** Visibility options the user may choose when writing a note. */
    public function allowedVisibilities(User $user): array
    {
        $options = [NoteVisibility::Team, NoteVisibility::Private];

        if ($user->hasPermission(Permissions::NOTE_VIEW_MANAGEMENT)) {
            $options[] = NoteVisibility::Management;
        }

        return $options;
    }

    public function create(Lead $lead, User $actor, string $content, NoteVisibility $visibility): LeadNote
    {
        return DB::transaction(function () use ($lead, $actor, $content, $visibility) {
            $note = new LeadNote(['note' => $content, 'visibility' => $visibility]);
            $note->lead_id = $lead->id;
            $note->created_by = $actor->id;
            $note->updated_by = $actor->id;
            $note->save();

            $this->activities->record($lead, ActivityService::NOTE_ADDED, "Added a {$visibility->value} note", ['note_id' => $note->id, 'visibility' => $visibility->value]);
            $this->audit->log(AuditAction::LeadNoteCreated, 'leads', $note, "Note added to {$lead->lead_number}", null, ['lead_id' => $lead->id, 'visibility' => $visibility->value]);

            return $note;
        });
    }

    public function update(LeadNote $note, User $actor, string $content, NoteVisibility $visibility): LeadNote
    {
        if ($note->note === $content && $note->visibility === $visibility) {
            return $note;
        }

        return DB::transaction(function () use ($note, $actor, $content, $visibility) {
            LeadNoteHistory::create([
                'lead_note_id' => $note->id,
                'old_content' => $note->note,
                'new_content' => $content,
                'old_visibility' => $note->visibility->value,
                'new_visibility' => $visibility->value,
                'edited_by' => $actor->id,
            ]);

            $oldVisibility = $note->visibility->value;
            $note->note = $content;
            $note->visibility = $visibility;
            $note->updated_by = $actor->id;
            $note->save();

            $lead = $note->lead;
            $this->activities->record($lead, ActivityService::NOTE_EDITED, 'Edited a note', ['note_id' => $note->id]);
            $this->audit->log(AuditAction::LeadNoteUpdated, 'leads', $note, "Note edited on {$lead->lead_number}", ['visibility' => $oldVisibility], ['visibility' => $visibility->value, 'lead_id' => $lead->id]);

            return $note;
        });
    }

    public function delete(LeadNote $note, User $actor): void
    {
        DB::transaction(function () use ($note, $actor) {
            $note->forceFill(['updated_by' => $actor->id])->save();
            $note->delete();

            $lead = $note->lead;
            $this->activities->record($lead, ActivityService::NOTE_DELETED, 'Deleted a note', ['note_id' => $note->id]);
            $this->audit->log(AuditAction::LeadNoteDeleted, 'leads', $note, "Note deleted on {$lead->lead_number}", null, ['lead_id' => $lead->id]);
        });
    }
}
