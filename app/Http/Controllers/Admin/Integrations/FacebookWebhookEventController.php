<?php

namespace App\Http\Controllers\Admin\Integrations;

use App\Enums\FacebookEventStatus;
use App\Enums\MetaErrorCategory;
use App\Http\Controllers\Controller;
use App\Models\FacebookForm;
use App\Models\FacebookPage;
use App\Models\FacebookWebhookEvent;
use App\Services\Meta\MetaLeadIngestionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webhook event ledger (facebook.manage). Rows hold Meta ids and safe error
 * text only — never lead answers, tokens or signatures. Server-side filters
 * and pagination; no export.
 */
class FacebookWebhookEventController extends Controller
{
    public function __construct(private readonly MetaLeadIngestionService $ingestion) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(FacebookEventStatus::class)],
            'category' => ['nullable', Rule::enum(MetaErrorCategory::class)],
            'fb_page' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'fb_form' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'search' => ['nullable', 'string', 'max:64', 'regex:/^[0-9]+$/'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $events = FacebookWebhookEvent::query()
            ->with(['page:id,page_name', 'form:id,form_name', 'lead:id,lead_number'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('processing_status', $v))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('error_category', $v))
            ->when($filters['fb_page'] ?? null, fn ($q, $v) => $q->where('page_id', $v))
            ->when($filters['fb_form'] ?? null, fn ($q, $v) => $q->where('form_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where('leadgen_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('received_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('received_at', '<', now()->parse($v)->addDay()))
            ->latest('received_at')->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (FacebookWebhookEvent $e) => $this->present($e));

        return Inertia::render('Admin/Integrations/Facebook/Events', [
            'events' => $events,
            'filters' => $filters,
            'statuses' => collect(FacebookEventStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'categories' => collect(MetaErrorCategory::cases())->map(fn ($c) => ['value' => $c->value, 'label' => ucfirst(str_replace('_', ' ', $c->value))]),
            'pages' => FacebookPage::query()->orderBy('page_name')->get(['page_id', 'page_name'])->unique('page_id')->values(),
            'forms' => FacebookForm::query()->orderBy('form_name')->get(['form_id', 'form_name'])->unique('form_id')->values(),
            'counts' => FacebookWebhookEvent::query()->selectRaw('processing_status, COUNT(*) as aggregate')->groupBy('processing_status')->pluck('aggregate', 'processing_status'),
        ]);
    }

    public function retry(Request $request, FacebookWebhookEvent $facebookEvent): RedirectResponse
    {
        try {
            $this->ingestion->retry($facebookEvent, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Event {$facebookEvent->leadgen_id} queued for retry.");
    }

    private function present(FacebookWebhookEvent $e): array
    {
        return [
            'id' => $e->id,
            'leadgen_id' => $e->leadgen_id,
            'origin' => $e->origin,
            'page_id' => $e->page_id,
            'page_name' => $e->page?->page_name,
            'form_id' => $e->form_id,
            'form_name' => $e->form?->form_name,
            'ad_id' => $e->ad_id,
            'status' => $e->processing_status?->value,
            'status_label' => $e->processing_status?->label(),
            'status_color' => $e->processing_status?->color(),
            'outcome' => $e->outcome,
            'reason' => $e->error_code,
            'category' => $e->error_category?->value,
            'category_help' => $e->error_category?->message(),
            'error_message' => $e->error_message,
            'attempts' => $e->attempt_count,
            'deliveries' => $e->delivery_count,
            'meta_created_at' => $e->meta_created_at?->toIso8601String(),
            'received_at' => $e->received_at?->toIso8601String(),
            'processed_at' => $e->processed_at?->toIso8601String(),
            'failed_at' => $e->failed_at?->toIso8601String(),
            'next_attempt_at' => $e->next_attempt_at?->toIso8601String(),
            'lead' => $e->lead ? ['id' => $e->lead->id, 'lead_number' => $e->lead->lead_number, 'url' => route('leads.show', $e->lead->id)] : null,
            'can_retry' => $e->isFailed(),
        ];
    }
}
