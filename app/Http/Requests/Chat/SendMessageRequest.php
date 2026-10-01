<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('send', $this->route('conversation'));
    }

    public function rules(): array
    {
        $extensions = implode(',', config('crm.chat.allowed_extensions'));

        return [
            'message' => ['nullable', 'string', 'max:'.config('crm.chat.max_message_length'), 'required_without:attachments'],
            'reply_to_message_id' => ['nullable', 'integer'],
            'attachments' => ['nullable', 'array', 'max:'.config('crm.chat.max_attachments')],
            'attachments.*' => [
                'file',
                'max:'.config('crm.chat.max_attachment_kb'),
                'mimes:'.$extensions,
                'extensions:'.$extensions,
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'message.required_without' => 'Type a message or attach a file.',
            'attachments.*.max' => 'Each file must be '.round(config('crm.chat.max_attachment_kb') / 1024).' MB or smaller.',
            'attachments.*.mimes' => 'This file type is not allowed.',
            'attachments.*.extensions' => 'This file type is not allowed.',
        ];
    }
}
