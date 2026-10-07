<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(Role::class)],
            'title' => ['nullable', 'string', 'max:80'],
            'movement_ids' => ['array'],
            'movement_ids.*' => ['integer', 'exists:movements,id'],
            'board_movement_id' => ['nullable', 'required_if:role,board', 'integer', 'exists:movements,id'],
            'confirm_replace' => ['boolean'],
            'chair_mode' => ['nullable', 'in:new,replace'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
    }
}
