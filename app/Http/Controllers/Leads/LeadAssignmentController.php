<?php

namespace App\Http\Controllers\Leads;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\LeadAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LeadAssignmentController extends Controller
{
    public function __construct(private readonly LeadAssignmentService $assignments) {}

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorize('assign', $lead);

        $data = $request->validate([
            'assigned_to' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $changed = $this->assignments->assignManually(
            $lead,
            $request->user(),
            (int) $data['assigned_to'],
            $data['reason'] ?? null,
        );

        if (! $changed) {
            return back()->with('success', 'Assignment unchanged.');
        }

        $lead->refresh();

        return $request->user()->can('view', $lead)
            ? back()->with('success', 'Lead assigned to '.$lead->assignee?->name.'.')
            : redirect()->route('leads.index')->with('success', "Lead {$lead->lead_number} reassigned.");
    }

    public function bulk(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'lead_ids' => ['required', 'array', 'min:1', 'max:'.LeadAssignmentService::MAX_BULK],
            'lead_ids.*' => ['integer', 'distinct'],
            'assigned_to' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $changed = $this->assignments->assignMany(
            $request->user(),
            $data['lead_ids'],
            (int) $data['assigned_to'],
            $data['reason'] ?? null,
        );

        if ($changed === 0) {
            return back()->with('success', 'Assignment unchanged.');
        }

        $name = User::query()->whereKey($data['assigned_to'])->value('name');

        return back()->with('success', $changed === 1
            ? "1 lead assigned to {$name}."
            : "{$changed} leads assigned to {$name}.");
    }
}
