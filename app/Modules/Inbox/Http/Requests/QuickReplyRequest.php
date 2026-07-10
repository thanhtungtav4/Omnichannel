<?php

namespace App\Modules\Inbox\Http\Requests;

use App\Modules\Platform\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuickReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Same RBAC as channels/integrations: owner/admin only.
        return in_array($this->user()?->role, ['owner', 'admin'], true);
    }

    public function rules(): array
    {
        $workspaceId = app(CurrentWorkspace::class)->id();
        $ignoreId = $this->route('quickReply')?->id;

        return [
            'shortcut' => [
                'required', 'string', 'max:40',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('quick_replies', 'shortcut')
                    ->where('workspace_id', $workspaceId)
                    ->ignore($ignoreId),
            ],
            'label' => ['required', 'string', 'max:120'],
            'text' => ['required', 'string', 'max:2000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return [
            'shortcut.regex' => 'Phím tắt chỉ gồm chữ thường, số và dấu gạch ngang.',
            'shortcut.unique' => 'Phím tắt này đã tồn tại.',
        ];
    }
}
