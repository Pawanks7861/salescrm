<?php

namespace App\Http\Controllers;

use App\Services\Notifications\FcmService;
use Illuminate\Http\Response;

/**
 * Service worker for the Firebase messaging scope. It is separate from
 * /sw.js so the existing Web Push subscription is left alone. Only the
 * public web config is embedded.
 */
class FcmServiceWorkerController extends Controller
{
    public function __invoke(FcmService $fcm): Response
    {
        $config = $fcm->webConfig();
        abort_unless(is_array($config), 404);

        $json = json_encode([
            'apiKey' => $config['apiKey'],
            'authDomain' => $config['authDomain'],
            'projectId' => $config['projectId'],
            'messagingSenderId' => $config['messagingSenderId'],
            'appId' => $config['appId'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $script = <<<JS
            importScripts('https://www.gstatic.com/firebasejs/11.10.0/firebase-app-compat.js');
            importScripts('https://www.gstatic.com/firebasejs/11.10.0/firebase-messaging-compat.js');
            firebase.initializeApp({$json});
            const messaging = firebase.messaging();
            messaging.onBackgroundMessage((payload) => {
                const note = payload && payload.notification;
                if (note && note.title) return;
                const data = (payload && payload.data) || {};
                if (!data.id || !data.title) return;
                const path = typeof data.url === 'string' && data.url.startsWith('/') && !data.url.startsWith('//') ? data.url : '/notifications';
                return self.registration.showNotification(String(data.title), {
                    body: String(data.body || ''),
                    tag: String(data.id),
                    data: { url: path },
                });
            });
            self.addEventListener('notificationclick', (event) => {
                event.notification.close();
                const path = event.notification.data && event.notification.data.url;
                const target = new URL(typeof path === 'string' && path.startsWith('/') ? path : '/notifications', self.location.origin).href;
                event.waitUntil((async () => {
                    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
                    const client = windows.find((c) => c.url && c.url.startsWith(self.location.origin));
                    if (client) {
                        await client.focus();
                        if (client.navigate) await client.navigate(target);
                        return;
                    }
                    await self.clients.openWindow(target);
                })());
            });
            JS;

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/firebase-cloud-messaging-push-scope',
        ]);
    }
}
