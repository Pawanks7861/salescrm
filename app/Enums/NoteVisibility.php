<?php

namespace App\Enums;

enum NoteVisibility: string
{
    /** Creator and users holding note.view_private. */
    case Private = 'private';
    /** Everyone who can view the lead. The stored value 'team' is legacy. */
    case Team = 'team';
    /** Creator and users holding note.view_management. */
    case Management = 'management';

    public function label(): string
    {
        return $this === self::Team ? 'Shared' : ucfirst($this->value);
    }
}
