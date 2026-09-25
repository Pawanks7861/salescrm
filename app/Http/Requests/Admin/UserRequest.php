<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Shared by create and update; password is required only on create. */
class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            ? $this->user()->can('update', $target)
            : $this->user()->can('create', User::class);
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name' => ['required', 'string', 'max:150'],
            'employee_code' => ['nullable', 'string', 'max:50', Rule::unique('users')->ignore($userId)],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:191', Rule::unique('users')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'designation' => ['nullable', 'string', 'max:100'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'is_active' => ['boolean'],
            'password' => [$userId ? 'prohibited' : 'required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
