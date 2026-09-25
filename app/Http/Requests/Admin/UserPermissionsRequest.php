<?php

namespace App\Http\Requests\Admin;

use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('managePermissions', $this->route('user'));
    }

    public function rules(): array
    {
        return [
            'grant' => ['array'],
            'grant.*' => ['string', Rule::in(Permissions::names())],
            'deny' => ['array'],
            'deny.*' => ['string', Rule::in(Permissions::names())],
        ];
    }
}
