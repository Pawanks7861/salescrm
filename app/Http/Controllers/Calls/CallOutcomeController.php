<?php

namespace App\Http\Controllers\Calls;

use App\Http\Controllers\Controller;
use App\Http\Requests\Calls\CompleteCallRequest;
use App\Models\Call;
use App\Services\Telephony\CallCompletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CallOutcomeController extends Controller
{
    public function __construct(private readonly CallCompletionService $completion) {}

    public function store(CompleteCallRequest $request, Call $call): RedirectResponse
    {
        $this->authorize('dispose', $call);

        $result = $this->completion->complete($call, $request->validated(), $request->user());

        $extra = match (true) {
            $result['followup'] !== null => ' Follow-up scheduled.',
            $result['meeting'] !== null => " Meeting {$result['meeting']->meeting_number} scheduled.",
            default => '',
        };

        return back()->with('success', 'Call outcome saved.'.$extra);
    }

    public function notes(Request $request, Call $call): RedirectResponse
    {
        $this->authorize('editNotes', $call);
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:5000']]);

        $this->completion->updateNotes($call, $data['notes'] ?? null, $request->user());

        return back()->with('success', 'Call notes saved.');
    }
}
