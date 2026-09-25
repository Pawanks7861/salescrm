<?php

namespace App\Notifications\Contracts;

/**
 * A database notification that may also be delivered as a browser (Web Push)
 * notification. Return null to skip push for this instance. Keep the text
 * short and free of sensitive data: it can appear on a device lock screen.
 * `event` is one of App\Support\PushEvent.
 */
interface BrowserPushable
{
    /** @return array{event: string, title: string, body: string}|null */
    public function toBrowserPush(object $notifiable): ?array;
}
