<?php

namespace App\Http\Requests;

use App\Models\Contact;
use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        $contact = $this->route('contact');

        return $contact ? $this->user()->can('update', $contact) : $this->user()->can(Permissions::CONTACTS_MANAGE);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'wa_id' => Contact::normalizeWaId((string) $this->input('phone')),
            'name' => trim((string) $this->input('name')) ?: null,
            'email' => strtolower(trim((string) $this->input('email'))) ?: null,
        ]);
    }

    public function rules(): array
    {
        /** @var Contact|null $contact */
        $contact = $this->route('contact');
        $accountId = $contact?->whatsapp_account_id ?? (int) $this->input('whatsapp_account_id');

        return [
            'whatsapp_account_id' => [$contact ? 'prohibited' : 'required', 'integer'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9 ()\-]{8,20}$/'],
            'wa_id' => [
                'required', 'digits_between:8,15',
                Rule::unique('contacts', 'wa_id')->where('whatsapp_account_id', $accountId)->ignore($contact?->id),
            ],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'tags' => ['nullable', 'string', 'max:500'],
            'opt_in_status' => ['required', Rule::in([Contact::OPT_UNKNOWN, Contact::OPT_IN, Contact::OPT_OUT])],
            'opt_in_source' => ['nullable', Rule::in(['manual', 'website', 'whatsapp_inbound', 'whatsapp_keyword', 'csv_import', 'other'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if (! $this->route('contact') && $this->filled('whatsapp_account_id')
                && ! $this->user()->canAccessAccount((int) $this->input('whatsapp_account_id'))) {
                $v->errors()->add('whatsapp_account_id', 'You do not have access to this WhatsApp account.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter the full number with country code, e.g. +91 98765 43210.',
            'wa_id.unique' => 'This number already exists for this WhatsApp account.',
            'wa_id.digits_between' => 'Phone number must have 8–15 digits including country code.',
        ];
    }

    public function attributes(): array
    {
        return ['whatsapp_account_id' => 'WhatsApp account', 'opt_in_status' => 'opt-in status'];
    }
}
