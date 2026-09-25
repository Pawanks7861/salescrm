<?php

namespace App\Enums;

enum CustomFieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case DateTime = 'datetime';
    case Dropdown = 'dropdown';
    case MultiSelect = 'multiselect';
    case Checkbox = 'checkbox';
    case Textarea = 'textarea';

    public function hasOptions(): bool
    {
        return in_array($this, [self::Dropdown, self::MultiSelect], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::DateTime => 'Date & time',
            self::MultiSelect => 'Multi-select',
            default => ucfirst($this->value),
        };
    }
}
