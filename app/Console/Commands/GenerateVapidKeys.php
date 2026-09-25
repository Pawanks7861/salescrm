<?php

namespace App\Console\Commands;

use App\Services\Notifications\PushEncryptionUnavailable;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/** Prints a new VAPID key pair for .env. Nothing is written to disk or logged. */
class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:vapid';

    protected $description = 'Generate VAPID keys for browser push notifications (copy them into .env)';

    public function handle(): int
    {
        try {
            PushEncryptionUnavailable::check();
            $keys = VAPID::createVapidKeys();
        } catch (\RuntimeException) {
            $this->error('PHP OpenSSL cannot create EC keys. Point OPENSSL_CONF at an openssl.cnf (on WAMP: bin\\php\\php8.x\\extras\\ssl\\openssl.cnf) and retry.');

            return self::FAILURE;
        }

        $this->line('Add these to .env (keep the private key secret; never commit it):');
        $this->newLine();
        $this->line('WEBPUSH_VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('WEBPUSH_VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('WEBPUSH_VAPID_SUBJECT=mailto:admin@your-company.com');
        $this->newLine();
        $this->warn('Changing keys invalidates existing browser subscriptions; users re-enable from My profile.');

        return self::SUCCESS;
    }
}
