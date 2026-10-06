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

    /** Users with this role are the only ones that can be assigned to batches as trainers. */
    public const TRAINER_ROLE = 'trainer';

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
            'last_seen_at' => 'datetime',
            'is_active' => 'boolean',
            'browser_notifications_enabled' => 'boolean',
            'notification_sound_enabled' => 'boolean',
            'lead_list_columns' => 'array',
            'password' => 'hashed',
        ];
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function fcmTokens(): HasMany
    {
        return $this->hasMany(FcmToken::class);
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

    /** Active, non-deleted users holding the Trainer role: the only users that may be newly assigned to a batch. */
    public function scopeEligibleTrainer(Builder $query): Builder
    {
        return $query->active()->whereHas('role', fn ($r) => $r->where('slug', self::TRAINER_ROLE));
    }

    public function trainedBatches(): BelongsToMany
    {
        return $this->belongsToMany(Batch::class, 'batch_trainers', 'trainer_id', 'batch_id')->withPivot('assigned_by', 'created_at');
    }

    /**
     * Users whose effective permissions include $permission, resolved in SQL with
     * the same rules as PermissionRegistrar: super admin, or (role grant OR user
     * grant) AND NOT user deny.
     */
    public function scopeWithPermission(Builder $query, string $permission): Builder
    {
        $override = fn (string $type) => fn ($q) => $q->selectRaw('1')
            ->from('user_permissions')
            ->join('permissions', 'permissions.id', '=', 'user_permissions.permission_id')
            ->whereColumn('user_permissions.user_id', 'users.id')
            ->where('permissions.name', $permission)
            ->where('user_permissions.type', $type);

        return $query->where(function (Builder $q) use ($permission, $override) {
            $q->whereHas('role', fn ($r) => $r->where('slug', self::SUPER_ADMIN_ROLE))
                ->orWhere(function (Builder $q) use ($permission, $override) {
                    $q->where(function (Builder $q) use ($permission, $override) {
                        $q->whereExists(fn ($s) => $s->selectRaw('1')
                            ->from('role_permissions')
                            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                            ->whereColumn('role_permissions.role_id', 'users.role_id')
                            ->where('permissions.name', $permission))
                            ->orWhereExists($override('grant'));
                    })->whereNotExists($override('deny'));
                });
        });
    }

    public function isSuperAdmin(): bool
    {
        return $this->role?->slug === self::SUPER_ADMIN_ROLE;
    }

    public function isTrainer(): bool
    {
        return $this->role?->slug === self::TRAINER_ROLE;
    }

    /** Super Admin or the Admin role. Sales roles are not included. */
    public function isAdmin(): bool
    {
        return $this->isSuperAdmin() || $this->role?->slug === 'admin';
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
