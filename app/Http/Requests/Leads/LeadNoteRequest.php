<?php

namespace App\Http\Requests\Leads;

use App\Enums\NoteVisibility;
use App\Services\Leads\LeadNoteService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Authorisation is performed in the controller (addNote / update policy). */
class LeadNoteRequest extends FormRequest
{
    public function rules(): array
    {
        $allowed = array_map(fn (NoteVisibility $v) => $v->value, app(LeadNoteService::class)->allowedVisibilities($this->user()));

        return [
            'note' => ['required', 'string', 'max:10000'],
            'visibility' => ['required', Rule::in($allowed)],
        ];
    }
}
