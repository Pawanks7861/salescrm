<?php

namespace App\Notifications;

use App\Notifications\Contracts\BrowserPushable;
use Illuminate\Notifications\Notification;

/** A live check from System settings. In-app for every active user; push is sent by the controller so it does not wait on the queue. */
class AdminAlertNotification extends Notification implements BrowserPushable
{
    public const TITLE = 'CRM alert';

    public const BODY = 'Your administrator sent a live notification check.';

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'category' => 'system',
            'event' => 'admin_alert',
            'message' => 'Alert from your administrator: this is a live notification check.',
            'url' => route('notifications.index', [], false),
        ];
    }

    public function toBrowserPush(object $notifiable): ?array
    {
        return [
            'event' => 'ADMIN_ALERT',
            'title' => self::TITLE,
            'body' => self::BODY,
        ];
    }
}
