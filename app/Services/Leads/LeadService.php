<?php

namespace App\Services\Leads;

use App\Enums\AssignmentType;
use App\Enums\AuditAction;
use App\Enums\LeadPriority;
use App\Events\LeadCreated;
use App\Events\LeadStatusChanged;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\LeadEnquiry;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\LeadStatusChange;
use App\Models\LostReason;
use App\Models\User;
use App\Services\ActivityService;
use App\Services\AuditService;
use App\Services\SettingService;
use App\Support\Permissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lead lifecycle: create, update, status/priority changes, archive/restore,
 * inbound (webhook/import) creation and deduplicated view auditing.
 */
class LeadService
{
    private const CONTACT_FIELDS = [
        'first_name', 'last_name', 'email', 'phone', 'alternate_phone', 'company_name',
        'designation', 'city', 'state', 'country', 'pincode', 'estimated_value',
    ];

    private const VIEW_AUDIT_TTL_MINUTES = 30;

    public function __construct(
        private readonly LeadNumberService $numbers,
        private readonly PhoneNormalizer $phones,
        private readonly LeadDuplicateService $duplicates,
        private readonly LeadAssignmentService $assignments,
        private readonly LeadAssignmentEngine $engine,
        private readonly LeadCustomFieldService $customFields,
        private readonly LeadVisibility $visibility,
        private readonly ActivityService $activities,
        private readonly AuditService $audit,
        private readonly SettingService $settings,
    ) {}

    /**
     * Manual creation by a user. `$data` must already be validated.
     *
     * @throws ValidationException
     */
    public function create(array $data, User $actor, bool $confirmDuplicate = false): Lead
    {
        $visibleMatches = $this->duplicates->findVisibleMatches($data, $actor);

        if ($visibleMatches->isNotEmpty() && ! $confirmDuplicate && $this->duplicates->mode() !== LeadDuplicateService::MODE_ALLOW) {
            throw ValidationException::withMessages([
                'duplicate' => 'A lead with the same phone or email already exists. Review the matches and confirm to continue.',
            ]);
        }

        $canAssign = $actor->hasPermission(Permissions::LEAD_ASSIGN);
        $target = null;

        if ($canAssign && ! empty($data['assigned_to'])) {
            $target = $this->assignments->resolveAssignableUser($actor, (int) $data['assigned_to']);
        }

        $lead = DB::transaction(function () use ($data, $actor, $canAssign, $target) {
            [$lead] = $this->persistNew($data, $actor, AssignmentType::Manual);

            if ($target) {
                $this->assignments->assign($lead, $target, AssignmentType::Manual, $actor);
            } elseif (! $canAssign) {
                $this->assignments->assign($lead, $actor, AssignmentType::Automatic, $actor, 'Creator became owner');
            } elseif (! $this->engine->apply($lead, $actor) || ($lead->assigned_to === null && ! $this->visibility->canView($actor, $lead))) {
                // Never let a lead disappear from its creator's view.
                $this->assignments->assign($lead, $actor, AssignmentType::Automatic, $actor, 'No rule matched; creator became owner');
            }

            return $lead;
        });

        LeadCreated::dispatch($lead);

        return $lead;
    }

    /**
     * Creation from an external channel (Facebook webhook, import…). Honours
     * the duplicate setting: "merge" attaches a new enquiry to the existing lead.
     * `$data` must already be sanitised by the caller (allow-listed keys only).
     *
     * Options (all optional):
     *  - enquiry: [channel, external_id, metadata, received_at] for the enquiry row
     *  - fill_missing: merge mode fills EMPTY contact/custom fields only, never
     *    overwriting owner, team, status, priority, estimated value or notes
     *  - created_activity / merged_activity: human-readable timeline text
     *  - audit: extra safe identifiers for the audit entry
     *
     * @return array{lead: Lead, merged: bool, enquiry: LeadEnquiry, filled: array<int, string>}
     */
    public function createFromInbound(array $data, AssignmentType $channel, array $enquiryData = [], array $options = []): array
    {
        $mode = $this->duplicates->mode();
        $existing = $mode === LeadDuplicateService::MODE_ALLOW ? null : $this->duplicates->findAnyMatch($data);

        if ($existing && $mode === LeadDuplicateService::MODE_MERGE) {
            [$enquiry, $filled] = DB::transaction(function () use ($existing, $data, $channel, $enquiryData, $options) {
                $enquiry = $this->createEnquiry($existing, $data, $enquiryData, true, null, $options['enquiry'] ?? []);
                $filled = ! empty($options['fill_missing']) ? $this->fillMissing($existing, $data) : [];

                $this->activities->record($existing, ActivityService::ENQUIRY_RECEIVED, $options['merged_activity'] ?? 'New enquiry received and merged into this lead', array_filter([
                    'source_id' => $data['source_id'] ?? null,
                    'campaign_id' => $data['campaign_id'] ?? null,
                    'enquiry_id' => $enquiry->id,
                    'channel' => $channel->value,
                ]), null);
                $this->audit->log(AuditAction::LeadEnquiryReceived, 'leads', $existing, "Duplicate enquiry merged into {$existing->lead_number}", null, [
                    'source_id' => $data['source_id'] ?? null,
                    'campaign_id' => $data['campaign_id'] ?? null,
                    'enquiry_id' => $enquiry->id,
                    ...($options['audit'] ?? []),
                ]);

                return [$enquiry, $filled];
            });

            return ['lead' => $existing, 'merged' => true, 'enquiry' => $enquiry, 'filled' => $filled];
        }

        [$lead, $enquiry] = DB::transaction(function () use ($data, $channel, $enquiryData, $options) {
            [$lead, $enquiry] = $this->persistNew($data, null, $channel, $enquiryData, $options);
            $this->engine->apply($lead);

            return [$lead, $enquiry];
        });

        LeadCreated::dispatch($lead);

        return ['lead' => $lead, 'merged' => false, 'enquiry' => $enquiry, 'filled' => []];
    }

    /**
     * Merge policy: copies inbound values only into EMPTY contact fields and
     * empty custom fields. Returns the names of the fields that were filled.
     *
     * @return array<int, string>
     */
    private function fillMissing(Lead $lead, array $data): array
    {
        $fillable = ['last_name', 'email', 'phone', 'alternate_phone', 'company_name', 'designation', 'city', 'state', 'country', 'pincode'];

        foreach ($fillable as $field) {
            if (blank($lead->{$field}) && filled($data[$field] ?? null)) {
                $lead->{$field} = $data[$field];
            }
        }

        $this->applyDerived($lead);
        [$old, $new] = $this->audit->dirtyDiff($lead, ['updated_at', 'full_name', 'normalized_phone', 'normalized_alternate_phone']);

        if ($new !== []) {
            $lead->save();
            $this->activities->record($lead, ActivityService::LEAD_UPDATED, 'Filled missing '.implode(', ', array_map(fn ($k) => str_replace('_', ' ', $k), array_keys($new))).' from the new enquiry', ['fields' => array_keys($new)], null);
            $this->audit->log(AuditAction::LeadUpdated, 'leads', $lead, "Lead {$lead->lead_number} missing fields filled from enquiry", $old, $new, null);
        }

        $custom = [];
        if (! empty($data['custom_fields']) && is_array($data['custom_fields'])) {
            $current = $lead->customFieldValues()->with('field:id,slug')->get()->pluck('value', 'field.slug');
            $custom = collect($data['custom_fields'])->filter(fn ($v, $slug) => blank($current[$slug] ?? null) && filled($v))->all();
            if ($custom !== []) {
                $this->customFields->save($lead, $custom);
            }
        }

        return [...array_keys($new), ...array_keys($custom)];
    }

    public function update(Lead $lead, array $data, User $actor): Lead
    {
        DB::transaction(function () use ($lead, $data, $actor) {
            $lead->fill(collect($data)->only(self::CONTACT_FIELDS)->all());
            $this->applyDerived($lead);

            if ($actor->hasPermission(Permissions::LEAD_EDIT_SOURCE)) {
                if (array_key_exists('source_id', $data)) {
                    $lead->source_id = (int) $data['source_id'];
                }
                if (array_key_exists('campaign_id', $data)) {
                    $lead->campaign_id = $data['campaign_id'] ? (int) $data['campaign_id'] : null;
                }
            }

            $priorityChanged = false;
            if (! empty($data['priority']) && $lead->priority?->value !== $data['priority']) {
                $oldPriority = $lead->priority?->value;
                $lead->priority = LeadPriority::from($data['priority']);
                $priorityChanged = true;
            }

            [$old, $new] = $this->audit->dirtyDiff($lead, ['updated_at', 'full_name', 'normalized_phone', 'normalized_alternate_phone', 'priority']);

            if ($lead->isDirty()) {
                $lead->updated_by = $actor->id;
                $lead->save();
            }

            if ($new !== []) {
                $this->activities->record($lead, ActivityService::LEAD_UPDATED, 'Updated '.implode(', ', array_map(fn ($k) => str_replace('_', ' ', $k), array_keys($new))), ['fields' => array_keys($new)]);
                $this->audit->log(AuditAction::LeadUpdated, 'leads', $lead, "Lead {$lead->lead_number} updated", $old, $new);
            }

            if ($priorityChanged) {
                $this->logPriorityChange($lead, $oldPriority, $lead->priority->value);
            }

            if (array_key_exists('custom_fields', $data) && is_array($data['custom_fields'])) {
                $this->customFields->save($lead, $data['custom_fields']);
            }
        });

        return $lead;
    }

    public function changePriority(Lead $lead, LeadPriority $priority, User $actor): bool
    {
        $old = $lead->priority?->value;
        if ($old === $priority->value) {
            return false;
        }

        DB::transaction(function () use ($lead, $priority, $actor, $old) {
            $lead->forceFill(['priority' => $priority, 'updated_by' => $actor->id])->save();
            $this->logPriorityChange($lead, $old, $priority->value);
        });

        return true;
    }

    /**
     * Moves a lead to a new status. Won/Lost timestamps and the lost reason
     * reflect the CURRENT state and are cleared when a lead is reopened;
     * history lives in activities and the audit log.
     *
     * @throws ValidationException
     */
    public function changeStatus(Lead $lead, int $statusId, User $actor, ?int $lostReasonId = null, ?string $lostNotes = null): bool
    {
        $status = LeadStatus::query()->active()->whereKey($statusId)->first();
        if (! $status) {
            throw ValidationException::withMessages(['status_id' => 'The selected status is not available.']);
        }

        if ((int) $lead->status_id === $status->id) {
            return false;
        }

        $reason = null;
        if ($status->is_lost) {
            $reason = $lostReasonId ? LostReason::query()->active()->whereKey($lostReasonId)->first() : null;
            if (! $reason && $this->settings->get('lead.require_lost_reason', true)) {
                throw ValidationException::withMessages(['lost_reason_id' => 'Select a reason for losing this lead.']);
            }
        }

        $from = $lead->status()->first();

        DB::transaction(function () use ($lead, $status, $from, $reason, $lostNotes, $actor) {
            $previousReason = $lead->lostReason?->name;

            $lead->status_id = $status->id;
            $lead->updated_by = $actor->id;
            $lead->converted_at = $status->is_won ? ($lead->converted_at ?? now()) : null;
            $lead->lost_at = $status->is_lost ? now() : null;
            $lead->lost_reason_id = $status->is_lost ? $reason?->id : null;
            $lead->lost_reason_notes = $status->is_lost ? ($lostNotes ?: null) : null;
            $lead->save();

            LeadStatusChange::record($lead, $from?->id, $status->id, $actor->id);

            $type = match (true) {
                $status->is_won => ActivityService::LEAD_WON,
                $status->is_lost => ActivityService::LEAD_LOST,
                (bool) $from?->is_won || (bool) $from?->is_lost => ActivityService::LEAD_REOPENED,
                default => ActivityService::STATUS_CHANGED,
            };

            $description = match ($type) {
                ActivityService::LEAD_WON => "Marked as won ({$status->name})",
                ActivityService::LEAD_LOST => 'Marked as lost'.($reason ? " — {$reason->name}" : ''),
                ActivityService::LEAD_REOPENED => "Reopened from {$from?->name} to {$status->name}",
                default => "Status changed from {$from?->name} to {$status->name}",
            };

            $this->activities->record($lead, $type, $description, array_filter([
                'from_status_id' => $from?->id,
                'to_status_id' => $status->id,
                'lost_reason_id' => $reason?->id,
                'lost_reason_notes' => $lostNotes ?: null,
                'previous_lost_reason' => $type === ActivityService::LEAD_REOPENED ? $previousReason : null,
            ], fn ($v) => $v !== null));

            $this->audit->log(
                AuditAction::LeadStatusChanged,
                'leads',
                $lead,
                "Lead {$lead->lead_number} status: {$from?->name} → {$status->name}",
                ['status_id' => $from?->id, 'status' => $from?->name],
                array_filter(['status_id' => $status->id, 'status' => $status->name, 'lost_reason' => $reason?->name], fn ($v) => $v !== null),
            );
        });

        LeadStatusChanged::dispatch($lead, (int) $from?->id, $status->id);

        return true;
    }

    public function archive(Lead $lead, User $actor): void
    {
        DB::transaction(function () use ($lead, $actor) {
            $lead->forceFill(['updated_by' => $actor->id])->save();
            $lead->delete();

            $this->activities->record($lead, ActivityService::LEAD_ARCHIVED, 'Lead archived');
            $this->audit->log(AuditAction::LeadArchived, 'leads', $lead, "Lead {$lead->lead_number} archived");
        });
    }

    public function restore(Lead $lead, User $actor): void
    {
        DB::transaction(function () use ($lead, $actor) {
            $lead->restore();
            $lead->forceFill(['updated_by' => $actor->id])->save();

            $this->activities->record($lead, ActivityService::LEAD_RESTORED, 'Lead restored from archive');
            $this->audit->log(AuditAction::LeadRestored, 'leads', $lead, "Lead {$lead->lead_number} restored");
        });
    }

    /** Audits a lead view at most once per user/lead per 30 minutes. */
    public function recordView(Lead $lead, User $user): void
    {
        $key = "lead-viewed:{$user->id}:{$lead->id}";

        if (Cache::add($key, true, now()->addMinutes(self::VIEW_AUDIT_TTL_MINUTES))) {
            $this->audit->log(AuditAction::LeadViewed, 'leads', $lead, "Viewed lead {$lead->lead_number}");
        }
    }

    /** @return array{0: Lead, 1: LeadEnquiry} */
    private function persistNew(array $data, ?User $actor, AssignmentType $channel, array $enquiryData = [], array $options = []): array
    {
        $lead = new Lead;
        $lead->fill(collect($data)->only(self::CONTACT_FIELDS)->all());
        $lead->priority = LeadPriority::tryFrom((string) ($data['priority'] ?? '')) ?? LeadPriority::Medium;
        $this->applyDerived($lead);

        $source = LeadSource::query()->whereKey($data['source_id'] ?? null)->first()
            ?? LeadSource::query()->where('is_default', true)->first()
            ?? LeadSource::query()->orderBy('id')->firstOrFail();
        $campaign = ! empty($data['campaign_id']) ? Campaign::find($data['campaign_id']) : null;
        $status = ! empty($data['status_id'])
            ? LeadStatus::query()->active()->where('is_won', false)->where('is_lost', false)->whereKey($data['status_id'])->first()
            : null;
        $status ??= LeadStatus::defaultStatus();

        $lead->forceFill([
            'lead_number' => $this->numbers->next(),
            'source_id' => $source->id,
            'campaign_id' => $campaign?->id,
            'status_id' => $status->id,
            'facebook_lead_id' => $data['facebook_lead_id'] ?? null,
            'facebook_form_id' => $data['facebook_form_id'] ?? null,
            'facebook_page_id' => $data['facebook_page_id'] ?? null,
            'facebook_ad_id' => $data['facebook_ad_id'] ?? null,
            'facebook_adset_id' => $data['facebook_adset_id'] ?? null,
            'facebook_campaign_id' => $data['facebook_campaign_id'] ?? null,
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);

        $duplicateOf = $this->duplicates->mode() === LeadDuplicateService::MODE_ALLOW ? null : $this->duplicates->findAnyMatch($data);
        if ($duplicateOf) {
            $lead->forceFill(['is_duplicate' => true, 'duplicate_of_id' => $duplicateOf->id]);
        }

        $lead->save();

        LeadStatusChange::record($lead, null, $status->id, $actor?->id, $lead->created_at);

        $enquiry = $this->createEnquiry($lead, $data, $enquiryData, (bool) $duplicateOf, $actor, $options['enquiry'] ?? []);

        if (! empty($data['custom_fields']) && is_array($data['custom_fields'])) {
            $this->customFields->save($lead, $data['custom_fields'], false);
        }

        $via = $actor ? "by {$actor->name}" : "via {$channel->value}";
        $this->activities->record($lead, ActivityService::LEAD_CREATED, $options['created_activity'] ?? "Lead created {$via} (source: {$source->name})", ['source_id' => $source->id, 'channel' => $channel->value], $actor?->id);

        $this->audit->log(AuditAction::LeadCreated, 'leads', $lead, "Lead {$lead->lead_number} created", null, [
            'lead_number' => $lead->lead_number,
            'name' => $lead->full_name,
            'source_id' => $source->id,
            'campaign_id' => $campaign?->id,
            'status_id' => $status->id,
            'channel' => $channel->value,
            ...($options['audit'] ?? []),
        ], $actor?->id);

        if ($duplicateOf) {
            $this->activities->record($lead, ActivityService::DUPLICATE_FLAGGED, 'Flagged as a possible duplicate of an existing lead', ['duplicate_of_id' => $duplicateOf->id], $actor?->id);
            $this->audit->log(AuditAction::LeadDuplicateDetected, 'leads', $lead, "Lead {$lead->lead_number} flagged as duplicate of {$duplicateOf->lead_number}", null, ['duplicate_of_id' => $duplicateOf->id], $actor?->id);
        }

        return [$lead, $enquiry];
    }

    private function createEnquiry(Lead $lead, array $data, array $enquiryData, bool $isDuplicate, ?User $actor, array $options = []): LeadEnquiry
    {
        $enquiry = new LeadEnquiry([
            'source_id' => $data['source_id'] ?? $lead->source_id,
            'campaign_id' => $data['campaign_id'] ?? $lead->campaign_id,
            'channel' => $options['channel'] ?? null,
            'external_id' => $options['external_id'] ?? $data['facebook_lead_id'] ?? null,
            'enquiry_data_json' => $enquiryData ?: collect($data)->only(['first_name', 'last_name', 'email', 'phone', 'company_name', 'city'])->filter()->all(),
            'metadata_json' => $options['metadata'] ?? null,
            'is_duplicate' => $isDuplicate,
            'received_at' => $options['received_at'] ?? now(),
        ]);
        $enquiry->lead_id = $lead->id;
        $enquiry->created_by = $actor?->id;
        $enquiry->save();

        return $enquiry;
    }

    private function applyDerived(Lead $lead): void
    {
        $lead->full_name = Lead::composeFullName($lead->first_name, $lead->last_name);
        $lead->email = $lead->email ? strtolower(trim($lead->email)) : null;
        $lead->normalized_phone = $this->phones->normalize($lead->phone);
        $lead->normalized_alternate_phone = $this->phones->normalize($lead->alternate_phone);
    }

    private function logPriorityChange(Lead $lead, ?string $old, string $new): void
    {
        $this->activities->record($lead, ActivityService::PRIORITY_CHANGED, 'Priority changed from '.ucfirst((string) $old).' to '.ucfirst($new), ['from' => $old, 'to' => $new]);
        $this->audit->log(AuditAction::LeadPriorityChanged, 'leads', $lead, "Lead {$lead->lead_number} priority: {$old} → {$new}", ['priority' => $old], ['priority' => $new]);
    }
}
