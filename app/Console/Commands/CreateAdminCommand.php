<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Server side admin provisioning:
 *
 *   php artisan admin:create --email=admin@example.com --password=Secret123 --name="Admin"
 *   php artisan admin:create                # fully interactive
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'admin:create
                            {--email= : Email address of the administrator}
                            {--password= : Plain password (prompted when omitted)}
                            {--name= : Display name}
                            {--phone= : Optional phone number}
                            {--force : Update the password/role when the email already exists}
                            {--require-password-change : Ask the user to change the password on first login}';

    protected $description = 'Create (or update) an administrator account with an email and a password';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Email address');
        $name = $this->option('name') ?: $this->ask('Full name', 'Administrator');
        $password = $this->option('password') ?: $this->secret('Password');

        if (! $this->option('password') && $password) {
            $confirmation = $this->secret('Confirm password');

            if ($password !== $confirmation) {
                $this->error('The two passwords do not match.');

                return self::FAILURE;
            }
        }

        $existing = User::withTrashed()->where('email', $email)->first();

        $validator = Validator::make([
            'email' => $email,
            'name' => $name,
            'password' => $password,
        ], [
            'email' => 'required|email|max:255',
            'name' => 'required|string|max:255',
            'password' => ['required', Password::min(8)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if ($existing && ! $this->option('force')) {
            $this->error("A user already exists with the email {$email}. Re-run with --force to update it.");

            return self::FAILURE;
        }

        $attributes = [
            'name' => $name,
            'password' => $password,
            'phone' => $this->option('phone'),
            'role' => User::ROLE_ADMIN,
            'scope' => User::SCOPE_GLOBAL,
            'is_active' => true,
            'must_change_password' => (bool) $this->option('require-password-change'),
        ];

        if ($existing) {
            $existing->restore();
            $existing->forceFill($attributes)->save();
            $user = $existing;
            $this->info("Administrator {$email} updated.");
        } else {
            $user = User::create(array_merge($attributes, ['email' => $email]));
            $this->info("Administrator {$email} created.");
        }

        $this->newLine();
        $this->table(
            ['ID', 'Name', 'Email', 'Role', 'Scope', 'Two-factor'],
            [[
                $user->id,
                $user->name,
                $user->email,
                $user->role,
                $user->scope,
                $user->hasTwoFactorEnabled() ? 'configured' : 'to configure on first login',
            ]]
        );

        $this->newLine();
        $this->line('  Google Authenticator is mandatory: the setup wizard runs automatically on the first login.');

        return self::SUCCESS;
    }
}
