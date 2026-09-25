<?php

namespace App\Http\Requests\Admin;

use App\Support\Permissions;
use App\Support\SettingDefinitions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Settings keys contain dots, so the payload is nested: settings[general][crm_name].
 */
class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission(Permissions::SETTINGS_MANAGE);
    }

    public function rules(): array
    {
        $rules = [];

        foreach (SettingDefinitions::forGroup($this->route('group')) as $key => $definition) {
            $rules['settings.'.$key] = $definition['rules'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        $attributes = [];

        foreach (SettingDefinitions::forGroup($this->route('group')) as $key => $definition) {
            $attributes['settings.'.$key] = $definition['label'];
        }

        return $attributes;
    }

    /** @return array<string, mixed> flat "group.key" => value */
    public function settingValues(): array
    {
        $group = $this->route('group');
        $values = [];

        foreach ($this->validated('settings.'.$group, []) as $name => $value) {
            $values[$group.'.'.$name] = $value;
        }

        return $values;
    }
}
