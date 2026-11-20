@php $editing = $plan->exists; @endphp
<x-app-layout :title="$editing ? 'Edit plan' : 'New plan'">
    <div class="mx-auto max-w-3xl">
        <x-page-header :title="$editing ? 'Edit '.$plan->name : 'New plan'" description="Leave a limit empty for unlimited.">
            <x-button variant="secondary" icon="arrow-left" :href="route('plans.index')">Plans</x-button>
        </x-page-header>

        <form method="POST" action="{{ $editing ? route('plans.update', $plan) : route('plans.store') }}" x-data="{ saving: false, bots: @js((bool) old('allow_bot_flows', $plan->allow_bot_flows)) }" @submit="saving = true">
            @csrf
            @if ($editing) @method('PUT') @endif

            <x-card title="Basics">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label class="form-label" for="name">Name</label>
                        <input id="name" name="name" class="form-input" value="{{ old('name', $plan->name) }}" required maxlength="80">
                        @error('name')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="slug">Slug</label>
                        <input id="slug" name="slug" class="form-input font-mono" value="{{ old('slug', $plan->slug) }}" placeholder="auto from name">
                        @error('slug')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="form-label" for="description">Description</label>
                        <input id="description" name="description" class="form-input" value="{{ old('description', $plan->description) }}" maxlength="500">
                    </div>
                    <div class="grid grid-cols-3 gap-2 sm:col-span-2">
                        <div>
                            <label class="form-label" for="price">Price</label>
                            <input id="price" name="price" type="number" step="0.01" min="0" class="form-input" value="{{ old('price', $plan->price ?? 0) }}" required>
                            @error('price')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="form-label" for="currency">Currency</label>
                            <input id="currency" name="currency" class="form-input uppercase" value="{{ old('currency', $plan->currency) }}" maxlength="3" required>
                        </div>
                        <div>
                            <label class="form-label" for="billing_period">Billing</label>
                            <select id="billing_period" name="billing_period" class="form-input">
                                @foreach (\App\Models\Plan::PERIODS as $v => $l)
                                    <option value="{{ $v }}" @selected(old('billing_period', $plan->billing_period) === $v)>{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="form-label" for="trial_days">Trial / validity days for new sign-ups</label>
                        <input id="trial_days" name="trial_days" type="number" min="1" class="form-input" value="{{ old('trial_days', $plan->trial_days) }}" placeholder="empty = no expiry">
                        <p class="form-help">New registrations on this (default) plan expire after these days.</p>
                    </div>
                    <div>
                        <label class="form-label" for="sort_order">Sort order</label>
                        <input id="sort_order" name="sort_order" type="number" min="0" class="form-input" value="{{ old('sort_order', $plan->sort_order ?? 0) }}">
                    </div>
                </div>
            </x-card>

            <x-card class="mt-6" title="Features & limits">
                <div class="space-y-3">
                    @foreach ([
                        'allow_embedded_signup' => 'Connect with Meta (WhatsApp Embedded Signup)',
                        'allow_manual' => 'Add WhatsApp numbers manually (own Meta app credentials)',
                    ] as $field => $label)
                        <label class="flex items-center gap-3 text-sm">
                            <input type="hidden" name="{{ $field }}" value="0">
                            <input type="checkbox" name="{{ $field }}" value="1" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500 dark:border-slate-600 dark:bg-slate-800" @checked(old($field, $plan->{$field}))>
                            {{ $label }}
                        </label>
                    @endforeach
                    <label class="flex items-center gap-3 text-sm">
                        <input type="hidden" name="allow_bot_flows" value="0">
                        <input type="checkbox" name="allow_bot_flows" value="1" x-model="bots" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500 dark:border-slate-600 dark:bg-slate-800">
                        Bot flows (auto-replies, menus)
                    </label>
                </div>

                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    @foreach ([
                        'max_whatsapp_accounts' => 'Max WhatsApp numbers',
                        'daily_message_limit' => 'Daily outgoing message limit',
                        'max_contacts' => 'Max contacts (manual + import)',
                    ] as $field => $label)
                        <div>
                            <label class="form-label" for="{{ $field }}">{{ $label }}</label>
                            <input id="{{ $field }}" name="{{ $field }}" type="number" min="0" class="form-input" value="{{ old($field, $plan->{$field}) }}" placeholder="Unlimited">
                            @error($field)<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                    <div x-show="bots">
                        <label class="form-label" for="max_bot_flows">Max bot flows</label>
                        <input id="max_bot_flows" name="max_bot_flows" type="number" min="0" class="form-input" value="{{ old('max_bot_flows', $plan->max_bot_flows) }}" placeholder="Unlimited">
                    </div>
                </div>
                <p class="form-help mt-3">Daily limit counts every outgoing message (agents, templates and bots) across all the customer's numbers; it resets at midnight ({{ config('app.timezone') }}). Incoming messages are never blocked.</p>
            </x-card>

            <x-card class="mt-6">
                <div class="flex flex-wrap gap-6">
                    @foreach (['is_active' => 'Active (can be assigned)', 'is_default' => 'Default plan for new registrations'] as $field => $label)
                        <label class="flex items-center gap-3 text-sm">
                            <input type="hidden" name="{{ $field }}" value="0">
                            <input type="checkbox" name="{{ $field }}" value="1" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500 dark:border-slate-600 dark:bg-slate-800" @checked(old($field, $plan->{$field}))>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </x-card>

            <div class="mt-6 flex justify-end gap-2">
                <x-button variant="secondary" :href="route('plans.index')">Cancel</x-button>
                <x-button ::disabled="saving"><span x-show="saving" class="spinner" x-cloak></span>{{ $editing ? 'Save plan' : 'Create plan' }}</x-button>
            </div>
        </form>
    </div>
</x-app-layout>
