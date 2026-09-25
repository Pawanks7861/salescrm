<?php

namespace App\Http\Controllers\Admin\Integrations;

use App\Http\Controllers\Controller;
use App\Models\FacebookForm;
use App\Services\Meta\MetaFieldMappingService;
use App\Services\Meta\MetaFormService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Per-form settings, field mapping (with preview) and lead backfill. Gated by facebook.manage. */
class FacebookFormController extends Controller
{
    public function __construct(
        private readonly MetaFormService $forms,
        private readonly MetaFieldMappingService $mapping,
    ) {}

    public function update(Request $request, FacebookForm $facebookForm): RedirectResponse
    {
        $validated = $request->validate([
            'is_enabled' => ['sometimes', 'boolean'],
            'lead_source_id' => ['sometimes', 'nullable', 'integer', Rule::exists('lead_sources', 'id')->where('is_active', true)],
        ]);

        $this->forms->update($facebookForm, $validated, $request->user());

        return back()->with('success', "Form \"{$facebookForm->form_name}\" updated.");
    }

    public function mapping(FacebookForm $facebookForm): Response
    {
        $facebookForm->load('page:id,page_id,page_name');

        return Inertia::render('Admin/Integrations/Facebook/FormMapping', [
            'form' => [
                'id' => $facebookForm->id,
                'form_id' => $facebookForm->form_id,
                'form_name' => $facebookForm->form_name,
                'page_name' => $facebookForm->page?->page_name,
                'is_enabled' => $facebookForm->is_enabled,
                'last_synced_at' => $facebookForm->last_synced_at?->toIso8601String(),
            ],
            'rows' => $this->mapping->rows($facebookForm),
            'targets' => $this->mapping->targetOptions(),
        ]);
    }

    public function saveMapping(Request $request, FacebookForm $facebookForm): RedirectResponse
    {
        $validated = $request->validate([
            'targets' => ['present', 'array', 'max:'.(int) config('meta.max_fields', 100)],
            'targets.*' => ['nullable', 'string', 'max:60'],
        ]);

        $count = $this->mapping->save($facebookForm, $validated['targets'], $request->user());

        return back()->with('success', "Field mapping saved ({$count} field(s) mapped).");
    }

    public function syncLeads(Request $request, FacebookForm $facebookForm): RedirectResponse
    {
        $validated = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:'.(int) config('meta.backfill_max_days', 90)]]);

        if (! $facebookForm->is_enabled || ! $facebookForm->page?->receivesLeads()) {
            return back()->with('error', 'Enable the form and its Page before syncing leads.');
        }

        $this->forms->requestBackfill($facebookForm, (int) $validated['days'], $request->user());

        return back()->with('success', "Sync of the last {$validated['days']} day(s) queued. New leads will appear shortly; leads already received are skipped.");
    }
}
