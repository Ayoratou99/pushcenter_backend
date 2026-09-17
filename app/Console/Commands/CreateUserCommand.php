<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Server side manager provisioning:
 *
 *   php artisan user:create --email=manager@example.com --password=Secret123 --scope=global
 *   php artisan user:create --email=manager@example.com --password=Secret123 --business=3 --business=7
 */
class CreateUserCommand extends Command
{
    protected $signature = 'user:create
                            {--email= : Email address}
                            {--password= : Plain password (prompted when omitted)}
                            {--name= : Display name}
                            {--phone= : Optional phone number}
                            {--role=manager : admin or manager}
                            {--scope=restricted : global (every application) or restricted}
                            {--business=* : Business id the manager is assigned to (repeatable, restricted scope only)}
                            {--force : Update the account when the email already exists}';

    protected $description = 'Create (or update) an internal user account';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Email address');
        $name = $this->option('name') ?: $this->ask('Full name');
        $password = $this->option('password') ?: $this->secret('Password');
        $role = $this->option('role');
        $scope = $role === User::ROLE_ADMIN ? User::SCOPE_GLOBAL : $this->option('scope');

        $validator = Validator::make([
            'email' => $email,
            'name' => $name,
            'password' => $password,
            'role' => $role,
            'scope' => $scope,
        ], [
            'email' => 'required|email|max:255',
            'name' => 'required|string|max:255',
            'password' => ['required', Password::min(8)->letters()->numbers()],
            'role' => 'required|in:admin,manager',
            'scope' => 'required|in:global,restricted',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $businessIds = array_map('intval', (array) $this->option('business'));

        if ($businessIds) {
            $missing = array_diff($businessIds, Business::whereIn('id', $businessIds)->pluck('id')->all());

            if ($missing) {
                $this->error('Unknown business id(s): ' . implode(', ', $missing));

                return self::FAILURE;
            }
        }

        $existing = User::withTrashed()->where('email', $email)->first();

        if ($existing && ! $this->option('force')) {
            $this->error("A user already exists with the email {$email}. Re-run with --force to update it.");

            return self::FAILURE;
        }

        $attributes = [
            'name' => $name,
            'password' => $password,
            'phone' => $this->option('phone'),
            'role' => $role,
            'scope' => $scope,
            'is_active' => true,
        ];

        if ($existing) {
            $existing->restore();
            $existing->forceFill($attributes)->save();
            $user = $existing;
        } else {
            $user = User::create(array_merge($attributes, ['email' => $email]));
        }

        if ($scope === User::SCOPE_GLOBAL) {
            $user->businesses()->detach();
        } else {
            $user->businesses()->sync($businessIds);
        }

        $this->info("User {$email} saved.");
        $this->table(
            ['ID', 'Email', 'Role', 'Scope', 'Applications'],
            [[
                $user->id,
                $user->email,
                $user->role,
                $user->scope,
                $scope === User::SCOPE_GLOBAL
                    ? 'all'
                    : ($user->businesses()->pluck('name')->implode(', ') ?: 'none'),
            ]]
        );

        return self::SUCCESS;
    }
}
