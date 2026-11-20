<?php

namespace App\Http\Requests;

use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WhatsAppAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account
            ? $this->user()->can('update', $account)
            : $this->user()->can('create', WhatsAppAccount::class);
    }

    protected function prepareForValidation(): void
    {
        $trim = fn ($v) => is_string($v) ? trim($v) : $v;

        $this->merge([
            'name' => $trim($this->input('name')),
            'meta_business_id' => $trim($this->input('meta_business_id')) ?: null,
            'waba_id' => $trim($this->input('waba_id')),
            'phone_number_id' => $trim($this->input('phone_number_id')),
            // Strip whitespace / accidental "Bearer " prefix from pasted tokens.
            'access_token' => preg_replace('/\s+/', '', (string) preg_replace('/^\s*Bearer\s+/i', '', (string) $this->input('access_token'))) ?: null,
            'app_secret' => $trim($this->input('app_secret')) ?: null,
            'webhook_verify_token' => $trim($this->input('webhook_verify_token')) ?: null,
            'graph_api_version' => $trim($this->input('graph_api_version')) ?: config('whatsapp.default_graph_version'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        /** @var WhatsAppAccount|null $account */
        $account = $this->route('account');
        $required = $account ? 'nullable' : 'required';

        return [
            'name' => ['required', 'string', 'max:120'],
            'meta_business_id' => ['nullable', 'regex:/^\d{5,30}$/'],
            'waba_id' => ['required', 'regex:/^\d{5,30}$/'],
            'phone_number_id' => [
                'required', 'regex:/^\d{5,30}$/',
                Rule::unique('whatsapp_accounts', 'phone_number_id')->ignore($account?->id),
            ],
            'access_token' => [$required, 'string', 'min:20', 'max:2048', 'regex:/^[A-Za-z0-9_\-|.]+$/'],
            'app_secret' => [$required, 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9]+$/'],
            'webhook_verify_token' => [$required, 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9_\-.~]+$/'],
            'graph_api_version' => ['required', 'regex:/^v\d{1,2}\.\d$/'],
            // Webhook id shown on the "Add account" form so the Callback URL can be copied before saving.
            'uuid' => $account ? ['prohibited'] : ['nullable', 'uuid', Rule::unique('whatsapp_accounts', 'uuid')],
            'is_active' => ['boolean'],
            // Only platform staff may choose an owner (customer whose plan limits apply).
            'owner_id' => $this->user()->isPlanLimited() ? ['prohibited'] : ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'waba_id.regex' => 'The WABA ID must contain only digits.',
            'phone_number_id.regex' => 'The Phone Number ID must contain only digits (it is NOT the phone number itself).',
            'phone_number_id.unique' => 'This Phone Number ID is already connected.',
            'meta_business_id.regex' => 'The Meta Business ID must contain only digits.',
            'access_token.regex' => 'The access token contains invalid characters.',
            'app_secret.regex' => 'The App Secret should contain only letters and numbers.',
            'webhook_verify_token.regex' => 'Use only letters, numbers, dash, underscore, dot or tilde.',
            'graph_api_version.regex' => 'Use the format v26.0',
        ];
    }

    public function attributes(): array
    {
        return [
            'waba_id' => 'WABA ID',
            'phone_number_id' => 'Phone Number ID',
            'meta_business_id' => 'Meta Business ID',
            'graph_api_version' => 'Graph API version',
            'webhook_verify_token' => 'webhook verify token',
            'app_secret' => 'App Secret',
        ];
    }
}
