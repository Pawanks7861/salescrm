<?php

namespace App\Http\Controllers\Leads;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\Leads\LeadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LeadStatusController extends Controller
{
    public function __construct(private readonly LeadService $leads) {}

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorize('changeStatus', $lead);

        $data = $request->validate([
            'status_id' => ['required', 'integer'],
            'lost_reason_id' => ['nullable', 'integer'],
            'lost_reason_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $changed = $this->leads->changeStatus(
            $lead,
            (int) $data['status_id'],
            $request->user(),
            isset($data['lost_reason_id']) ? (int) $data['lost_reason_id'] : null,
            $data['lost_reason_notes'] ?? null,
        );

        return back()->with('success', $changed ? "Status updated to {$lead->status()->value('name')}." : 'Status unchanged.');
    }
}
