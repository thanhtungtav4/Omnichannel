<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform-admin:create
        {--name= : Display name}
        {--email= : Login email}
        {--password= : Password (prompted if omitted)}';

    protected $description = 'Create an out-of-tenant platform admin (manages the admin console).';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $email = $this->option('email') ?: $this->ask('Email');
        $password = $this->option('password') ?: $this->secret('Password');

        $data = ['name' => $name, 'email' => $email, 'password' => $password];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'workspace_id' => null,
            'is_platform_admin' => true,
            'name' => $name,
            'display_name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => 'owner',
            'status' => 'ACTIVE',
            'email_verified_at' => now(),
        ]);

        $this->info("Platform admin {$user->email} created.");

        return self::SUCCESS;
    }
}
