<?php

namespace App\Models;

use App\Services\PermissionRegistrar;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    public const SUPER_ADMIN_ROLE = 'super_admin';

    /**
     * Ownership/security columns (role_id, team_id, manager_id, is_active) are
     * intentionally NOT fillable; UserService assigns them explicitly.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'employee_code',
        'email',
        'phone',
        'password',
        'designation',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @var array<string>|null */
    private ?array $resolvedPermissions = null;

    /** Same defaults as the columns, so new (unrefreshed) models behave identically. */
    protected $attributes = [
        'browser_notifications_enabled' => false,
        'notification_sound_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'browser_notifications_enabled' => 'boolean',
            'notification_sound_enabled' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** Legacy organisational metadata only; never used for record visibility. */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(User::class, 'manager_id');
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_users')->withTimestamps();
    }

    public function managedTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'manager_id');
    }

    public function permissionOverrides(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permissions')
            ->withPivot('type')
            ->withTimestamps();
    }

    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role?->slug === self::SUPER_ADMIN_ROLE;
    }

    /** @return array<string> */
    public function permissionNames(): array
    {
        return $this->resolvedPermissions ??= app(PermissionRegistrar::class)->permissionsFor($this);
    }

    public function hasPermission(string $permission): bool
    {
        return $this->isSuperAdmin() || in_array($permission, $this->permissionNames(), true);
    }

    public function hasAnyPermission(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function forgetResolvedPermissions(): void
    {
        $this->resolvedPermissions = null;
    }
}
