<?php

namespace App\Modules\Platform\Http\Requests;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkspaceRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        return (bool) $this->user()?->is_platform_admin;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('owner_email')) {
            $this->merge([
                'owner_email' => strtolower(trim((string) $this->input('owner_email'))),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'required', 'string', 'max:63',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn([config('tenant.admin_subdomain'), 'www', 'api', 'app']),
                Rule::unique('workspaces', 'slug'),
            ],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'owner_password' => $this->passwordRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug must be lowercase letters, numbers, and single hyphens.',
            'slug.not_in' => 'That slug is reserved.',
        ];
    }
}
