<?php

namespace App\Http\Presenters;

use App\Models\User;
use Illuminate\Support\Carbon;

/** The only trainer fields sent to batch pages: no email, phone or other profile data. */
final class TrainerPresenter
{
    /** Eager-load columns needed by present(). */
    public const COLUMNS = ['users.id', 'users.name', 'users.designation', 'users.role_id', 'users.is_active', 'users.deleted_at'];

    public static function present(User $user): array
    {
        $assignedAt = $user->pivot?->created_at;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'designation' => $user->designation,
            'role' => $user->role?->name,
            'active' => $user->is_active && ! $user->trashed(),
            'assigned_at' => $assignedAt ? Carbon::parse($assignedAt, 'UTC')->toIso8601String() : null,
        ];
    }
}
