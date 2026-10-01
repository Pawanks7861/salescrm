<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('message'));
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.config('crm.chat.max_message_length')],
        ];
    }
}
