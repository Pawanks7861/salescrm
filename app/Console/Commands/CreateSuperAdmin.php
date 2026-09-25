<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use App\Services\SettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates the first Super Admin interactively (production bootstrap). The
 * password is only ever read from a hidden prompt (never an option, so it
 * cannot land in shell history), is never printed, logged or emailed, and is
 * stored hashed. Refuses when a Super Admin already exists; changes go
 * through crm:reset-super-admin.
 */
class CreateSuperAdmin extends Command
{
    public const MIN_PASSWORD_LENGTH = 12;

    protected $signature = 'crm:create-super-admin
        {--name= : Full name (prompted when omitted)}
        {--email= : Login email (prompted when omitted)}';

    protected $description = 'Create a Super Admin account (interactive; the password is prompted, never passed as an argument)';

    public function handle(AuditService $audit, SettingService $settings): int
    {
        $roleId = Role::where('slug', User::SUPER_ADMIN_ROLE)->value('id');
        if (! $roleId) {
            $this->error('The Super Admin role does not exist. Run: php artisan db:seed --force');

            return self::FAILURE;
        }

        if (User::where('role_id', $roleId)->exists()) {
            $this->error('A Super Admin already exists; a second one is not created.');
            $this->line('To change its name, email or password run: php artisan crm:reset-super-admin');

            return self::FAILURE;
        }

        $input = [
            'name' => trim((string) ($this->option('name') ?: $this->ask('Full name'))),
            'email' => strtolower(trim((string) ($this->option('email') ?: $this->ask('Email')))),
            'password' => (string) $this->secret('Password (min '.$this->minLength($settings).' characters, upper/lower case, number and symbol)'),
            'password_confirmation' => (string) $this->secret('Confirm password'),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::min($this->minLength($settings))->letters()->mixedCase()->numbers()->symbols()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($input, $roleId, $audit) {
            $user = new User(['name' => $input['name'], 'email' => $input['email'], 'password' => $input['password']]);
            $user->role_id = $roleId;
            $user->is_active = true;
            $user->email_verified_at = now();
            $user->save();

            $audit->log(
                AuditAction::UserCreated,
                'users',
                $user,
                "Super Admin {$user->name} created from the console",
                null,
                $user->only(['name', 'email', 'role_id', 'is_active']),
            );

            return $user;
        });

        $this->info("Super Admin created: {$user->email}");

        return self::SUCCESS;
    }

    private function minLength(SettingService $settings): int
    {
        return max(self::MIN_PASSWORD_LENGTH, (int) $settings->get('security.password_min_length', 12));
    }
}
