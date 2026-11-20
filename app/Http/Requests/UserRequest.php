<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::USERS_MANAGE);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'email' => strtolower(trim((string) $this->input('email'))),
        ]);
    }

    public function rules(): array
    {
        /** @var User|null $target */
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($target?->id)],
            'password' => [$target ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::in(Permissions::roles())],
            'is_active' => ['boolean'],
            'account_ids' => ['array'],
            'account_ids.*' => ['integer', 'exists:whatsapp_accounts,id'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:32'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'plan_expires_at' => ['nullable', 'date'],
        ];
    }

    public function attributes(): array
    {
        return ['account_ids' => 'WhatsApp accounts'];
    }
}
