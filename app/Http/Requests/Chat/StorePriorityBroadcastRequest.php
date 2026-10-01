<?php

namespace App\Http\Requests\Chat;

use App\Models\PriorityBroadcast;
use App\Support\CrmTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * expires_at arrives as a CRM-local "Y-m-d\TH:i" (datetime-local input) and
 * is converted to UTC before the "must be in the future" check.
 */
class StorePriorityBroadcastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', PriorityBroadcast::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
            'expires_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('expires_at') || ! $this->filled('expires_at')) {
                    return;
                }

                $at = $this->expiresAtUtc();
                if ($at === null || $at->lte(now())) {
                    $validator->errors()->add('expires_at', 'The expiry must be in the future.');
                }
            },
        ];
    }

    public function expiresAtUtc(): ?CarbonImmutable
    {
        if (! $this->filled('expires_at')) {
            return null;
        }

        [$date, $time] = explode('T', (string) $this->input('expires_at'), 2) + [null, null];

        return $date && $time ? CrmTime::toUtc($date, $time) : null;
    }
}
