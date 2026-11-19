@php $editing = $contact->exists; @endphp
<x-app-layout :title="$editing ? 'Edit contact' : 'Add contact'">
    <div class="mx-auto max-w-3xl">
        <x-page-header :title="$editing ? 'Edit '.$contact->displayName() : 'Add contact'">
            <x-button variant="secondary" icon="arrow-left" :href="$editing ? route('contacts.show', $contact) : route('contacts.index')">Back</x-button>
        </x-page-header>

        <form method="POST" action="{{ $editing ? route('contacts.update', $contact) : route('contacts.store') }}" x-data="{ saving: false }" @submit="saving = true">
            @csrf
            @if ($editing) @method('PUT') @endif

            <x-card>
                <div class="grid gap-5 sm:grid-cols-2">
                    @unless ($editing)
                        <div class="sm:col-span-2">
                            <label class="form-label" for="whatsapp_account_id">WhatsApp number</label>
                            <select id="whatsapp_account_id" name="whatsapp_account_id" class="form-input" required>
                                @foreach ($accounts as $a)
                                    <option value="{{ $a->id }}" @selected((int) old('whatsapp_account_id', $contact->whatsapp_account_id) === $a->id)>{{ $a->label() }}</option>
                                @endforeach
                            </select>
                            <p class="form-help">Contacts are kept separately for each WhatsApp number.</p>
                            @error('whatsapp_account_id')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                    @endunless
                    <div>
                        <label class="form-label" for="phone">Phone number (with country code)</label>
                        <input id="phone" name="phone" class="form-input font-mono" value="{{ old('phone', $contact->phone) }}" required placeholder="+91 98765 43210" inputmode="tel">
                        @error('phone')<p class="form-error">{{ $message }}</p>@enderror
                        @error('wa_id')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="name">Name</label>
                        <input id="name" name="name" class="form-input" value="{{ old('name', $contact->name) }}" maxlength="120">
                        @error('name')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="email">Email</label>
                        <input id="email" type="email" name="email" class="form-input" value="{{ old('email', $contact->email) }}">
                        @error('email')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="tags">Tags</label>
                        <input id="tags" name="tags" class="form-input" value="{{ old('tags', $tagString) }}" placeholder="vip, lead, delhi">
                        <p class="form-help">Separate with commas.</p>
                        @error('tags')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="mt-6 border-t border-slate-100 pt-5 dark:border-slate-800">
                    <p class="text-sm font-semibold text-slate-900 dark:text-white">Consent / opt-in</p>
                    <p class="form-help">Meta requires opt-in before you send business-initiated (template) messages. Record how consent was given.</p>
                    <div class="mt-3 grid gap-5 sm:grid-cols-2">
                        <div>
                            <label class="form-label" for="opt_in_status">Status</label>
                            <select id="opt_in_status" name="opt_in_status" class="form-input">
                                @foreach (['unknown' => 'Unknown', 'opted_in' => 'Opted in', 'opted_out' => 'Opted out'] as $v => $l)
                                    <option value="{{ $v }}" @selected(old('opt_in_status', $contact->opt_in_status) === $v)>{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="opt_in_source">Source</label>
                            <select id="opt_in_source" name="opt_in_source" class="form-input">
                                @foreach (['manual' => 'Manual (staff)', 'website' => 'Website form', 'whatsapp_inbound' => 'Customer messaged first', 'whatsapp_keyword' => 'WhatsApp keyword', 'csv_import' => 'CSV import', 'other' => 'Other'] as $v => $l)
                                    <option value="{{ $v }}" @selected(old('opt_in_source', $contact->opt_in_source ?? 'manual') === $v)>{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    @if ($editing && ($contact->opted_in_at || $contact->opted_out_at))
                        <p class="mt-2 text-xs text-slate-500">
                            @if ($contact->opted_in_at) Opted in {{ $contact->opted_in_at->toDayDateTimeString() }}. @endif
                            @if ($contact->opted_out_at) Opted out {{ $contact->opted_out_at->toDayDateTimeString() }}. @endif
                        </p>
                    @endif
                </div>
            </x-card>

            <div class="mt-6 flex justify-end gap-2">
                <x-button variant="secondary" :href="$editing ? route('contacts.show', $contact) : route('contacts.index')">Cancel</x-button>
                <x-button ::disabled="saving"><span x-show="saving" class="spinner" x-cloak></span>{{ $editing ? 'Save contact' : 'Add contact' }}</x-button>
            </div>
        </form>
    </div>
</x-app-layout>
