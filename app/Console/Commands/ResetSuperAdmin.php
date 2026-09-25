<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditService;
use App\Services\SettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The explicit, separate way to change an existing Super Admin's name, login
 * email or password (crm:create-super-admin never creates a second one).
 * The password is only read from a hidden prompt, never printed or logged,
 * and every session and remember token of the account is revoked.
 */
class ResetSuperAdmin extends Command
{
    protected $signature = 'crm:reset-super-admin
        {--email= : Current login email of the Super Admin (prompted when there are several)}';

    protected $description = 'Change an existing Super Admin\'s name, email and password (interactive; the password is prompted, never passed as an argument)';

    public function handle(AuditService $audit, SettingService $settings): int
    {
        $admins = User::query()->whereHas('role', fn ($q) => $q->where('slug', User::SUPER_ADMIN_ROLE))->orderBy('id');
        if ($email = $this->option('email')) {
            $admins->where('email', strtolower(trim((string) $email)));
        }
        $candidates = $admins->get();

        if ($candidates->isEmpty()) {
            $this->error($email ? 'No Super Admin has that email.' : 'No Super Admin exists. Create one with: php artisan crm:create-super-admin');

            return self::FAILURE;
        }

        $user = $candidates->count() === 1
            ? $candidates->first()
            : $candidates->firstWhere('email', $this->choice('Which Super Admin?', $candidates->pluck('email')->all()));

        if (! $this->confirm("Change the name, email and password of {$user->email}? All of its sessions will be signed out.", false)) {
            $this->info('Nothing changed.');

            return self::SUCCESS;
        }

        $min = max(CreateSuperAdmin::MIN_PASSWORD_LENGTH, (int) $settings->get('security.password_min_length', 12));
        $input = [
            'name' => trim((string) $this->ask('Full name', $user->name)),
            'email' => strtolower(trim((string) $this->ask('Email', $user->email))),
            'password' => (string) $this->secret("New password (min {$min} characters, upper/lower case, number and symbol)"),
            'password_confirmation' => (string) $this->secret('Confirm new password'),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['required', 'string', 'confirmed', Password::min($min)->letters()->mixedCase()->numbers()->symbols()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $old = $user->only(['name', 'email']);

        DB::transaction(function () use ($user, $input, $old, $audit) {
            $user->forceFill([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'is_active' => true,
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->whereIn('email', array_unique([$old['email'], $input['email']]))->delete();

            $audit->log(
                AuditAction::UserPasswordReset,
                'users',
                $user,
                "Super Admin {$user->name} updated from the console (password reset, sessions revoked)",
                $old,
                $user->only(['name', 'email']),
            );
        });

        $this->info("Super Admin updated: {$user->email}. Sign in with the new password.");

        return self::SUCCESS;
    }
}
