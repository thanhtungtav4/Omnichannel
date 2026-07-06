<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $workspace = Workspace::query()->firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'CRM Demo Workspace', 'status' => 'ACTIVE'],
        );

        return User::create([
            'workspace_id' => $workspace->id,
            'name' => $input['name'],
            'display_name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'role' => 'support_agent',
            'status' => 'ACTIVE',
        ]);
    }
}
