<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\UserAgentParser;
use Illuminate\Http\Request;

class LoginHistoryService
{
    private const SESSION_KEY = 'login_history_id';

    public function __construct(
        private readonly AuditService $audit,
        private readonly Request $request,
    ) {}

    public function recordLogin(User $user): void
    {
        $history = $this->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'event' => 'login',
            'successful' => true,
            'logged_in_at' => now(),
        ]);

        if ($this->request->hasSession()) {
            $this->request->session()->put(self::SESSION_KEY, $history->id);
        }

        User::whereKey($user->id)->update([
            'last_login_at' => now(),
            'last_login_ip' => $this->request->ip(),
        ]);

        $this->audit->log(AuditAction::Login, 'auth', $user, "{$user->name} logged in", userId: $user->id);
    }

    public function recordLogout(User $user): void
    {
        $historyId = $this->request->hasSession() ? $this->request->session()->get(self::SESSION_KEY) : null;

        $updated = $historyId
            ? LoginHistory::whereKey($historyId)->where('user_id', $user->id)->whereNull('logged_out_at')->update(['logged_out_at' => now()])
            : 0;

        if (! $updated) {
            $this->create([
                'user_id' => $user->id,
                'email' => $user->email,
                'event' => 'logout',
                'successful' => true,
                'logged_out_at' => now(),
            ]);
        }

        $this->audit->log(AuditAction::Logout, 'auth', $user, "{$user->name} logged out", userId: $user->id);
    }

    public function recordFailed(?string $email, ?User $user): void
    {
        $this->create([
            'user_id' => $user?->id,
            'email' => $email ? mb_substr($email, 0, 191) : null,
            'event' => 'failed',
            'successful' => false,
        ]);

        $this->audit->log(
            AuditAction::LoginFailed,
            'auth',
            $user,
            'Failed login attempt for '.($email ?: 'unknown email'),
            newValues: ['email' => $email],
            userId: $user?->id,
        );
    }

    public function recordLockout(?string $email): void
    {
        $this->create([
            'email' => $email ? mb_substr($email, 0, 191) : null,
            'event' => 'lockout',
            'successful' => false,
        ]);

        $this->audit->log(
            AuditAction::LoginLockout,
            'auth',
            null,
            'Login locked out after too many attempts for '.($email ?: 'unknown email'),
            newValues: ['email' => $email],
        );
    }

    private function create(array $attributes): LoginHistory
    {
        $userAgent = $this->request->userAgent();

        return LoginHistory::create([
            ...$attributes,
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr((string) $userAgent, 0, 500),
            'session_id' => $this->request->hasSession() ? $this->request->session()->getId() : null,
            ...UserAgentParser::parse($userAgent),
        ]);
    }
}
