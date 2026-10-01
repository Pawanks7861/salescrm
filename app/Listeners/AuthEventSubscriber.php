<?php

namespace App\Listeners;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\LoginHistoryService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Throwable;

class AuthEventSubscriber
{
    public function __construct(private readonly LoginHistoryService $history) {}

    public function handleLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            try {
                $this->history->recordLogin($event->user);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->history->recordLogout($event->user);

            // This browser stops receiving the signed-out user's pushes (shared computers).
            $hash = request()->hasSession() ? request()->session()->get('push_subscription_hash') : null;
            if (is_string($hash)) {
                PushSubscription::query()->where('user_id', $event->user->id)->where('endpoint_hash', $hash)->delete();
            }
        }
    }

    public function handleFailed(Failed $event): void
    {
        $this->history->recordFailed(
            $event->credentials['email'] ?? null,
            $event->user instanceof User ? $event->user : null,
        );
    }

    public function handleLockout(Lockout $event): void
    {
        $this->history->recordLockout($event->request->input('email'));
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailed',
            Lockout::class => 'handleLockout',
        ];
    }
}
