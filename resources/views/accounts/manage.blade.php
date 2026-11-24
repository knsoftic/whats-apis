<x-app-layout title="WhatsApp Manager">
    @php
        $icons = ['health' => 'activity', 'profile' => 'user', 'security' => 'lock', 'blocked' => 'x-circle', 'charges' => 'chart'];
    @endphp
    <div class="mx-auto max-w-5xl">
        <x-page-header :title="'WhatsApp Manager · '.$account->name" :description="($account->display_phone_number ?: 'Phone Number ID '.$account->phone_number_id).($account->verified_name ? ' · '.$account->verified_name : '')">
            <x-button variant="secondary" icon="arrow-left" :href="route('accounts.show', $account)">Account</x-button>
        </x-page-header>

        <nav class="mb-6 flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-slate-800">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('accounts.manage', [$account, $key]) }}" @class(['flex shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium',
                    'border-brand-600 text-brand-700 dark:text-brand-400' => $tab === $key,
                    'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200' => $tab !== $key])>
                    <x-icon :name="$icons[$key]" class="h-4 w-4" />{{ $label }}
                </a>
            @endforeach
        </nav>

        {{-- =========================================================== HEALTH --}}
        @if ($tab === 'health')
            @php
                $p = $health['phone'] ?? [];
                $w = $health['waba'] ?? [];
                $quality = strtoupper((string) ($p['quality_rating'] ?? $account->quality_rating ?? 'UNKNOWN'));
                $qualityInfo = match ($quality) {
                    'GREEN' => ['High', 'green', 'Customers are happy with your messages.'],
                    'YELLOW' => ['Medium', 'amber', 'Some customers blocked or reported you recently. Send fewer marketing messages and only to people who asked for them.'],
                    'RED' => ['Low', 'red', 'Many blocks / reports. Meta may lower your messaging limit or pause templates — stop marketing messages and review them.'],
                    default => ['Not rated yet', 'slate', 'Meta rates quality after you have sent messages for a while.'],
                };
                $tier = (string) ($p['messaging_limit_tier'] ?? $limitEvent ?? '');
                $tierLabel = match (strtoupper($tier)) {
                    'TIER_50' => '50', 'TIER_250' => '250', 'TIER_1K' => '1,000', 'TIER_2K' => '2,000', 'TIER_10K' => '10,000', 'TIER_100K' => '100,000', 'TIER_UNLIMITED' => 'Unlimited',
                    default => $tier ?: null,
                };
                $canSend = $p['health_status']['can_send_message'] ?? $w['health_status']['can_send_message'] ?? null;
                $issues = collect([...($p['health_status']['entities'] ?? []), ...($w['health_status']['entities'] ?? [])])->flatMap(fn ($e) => $e['errors'] ?? [])->unique('error_code')->values();
            @endphp

            <div class="mb-5 flex flex-wrap items-center gap-3">
                <p class="text-sm text-slate-500">{{ $health ? 'Last checked '.\Illuminate\Support\Carbon::parse($health['fetched_at'])->diffForHumans().'.' : 'Not checked yet.' }}</p>
                <form method="POST" action="{{ route('accounts.manage.health', $account) }}" class="ml-auto">
                    @csrf
                    <x-button icon="refresh">Refresh from Meta</x-button>
                </form>
            </div>

            @if ($canSend)
                <div @class(['mb-5 flex items-start gap-3 rounded-2xl border p-4 text-sm',
                    'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-200' => $canSend === 'AVAILABLE',
                    'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-500/10 dark:text-amber-200' => $canSend === 'LIMITED',
                    'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-900 dark:bg-rose-500/10 dark:text-rose-200' => ! in_array($canSend, ['AVAILABLE', 'LIMITED'], true)])>
                    <x-icon :name="$canSend === 'AVAILABLE' ? 'check' : 'warning'" class="h-5 w-5 shrink-0" />
                    <div>
                        <p class="font-semibold">{{ match ($canSend) { 'AVAILABLE' => 'This number can send messages.', 'LIMITED' => 'Sending is limited.', default => 'Sending is blocked by Meta.' } }}</p>
                        @foreach ($issues as $issue)
                            <p class="mt-1">{{ $issue['error_description'] ?? '' }} @if (! empty($issue['possible_solution']))<span class="opacity-80">— {{ $issue['possible_solution'] }}</span>@endif</p>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <x-card>
                    <p class="text-sm text-slate-500">Quality rating</p>
                    <p class="mt-1 flex items-center gap-2 text-2xl font-semibold"><x-badge :color="$qualityInfo[1]" dot>{{ $qualityInfo[0] }}</x-badge></p>
                    <p class="mt-2 text-xs text-slate-500">{{ $qualityInfo[2] }}</p>
                    @if ($qualityEvent)<p class="mt-2 text-xs text-slate-400">Last Meta update: {{ $qualityEvent }}</p>@endif
                </x-card>
                <x-card>
                    <p class="text-sm text-slate-500">Messaging limit</p>
                    <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">{{ $tierLabel ?? '—' }}</p>
                    <p class="mt-2 text-xs text-slate-500">New customers you can start conversations with (templates) in 24 hours. It grows automatically when quality stays high.</p>
                </x-card>
                <x-card>
                    <p class="text-sm text-slate-500">Display name</p>
                    <p class="mt-1 truncate text-lg font-semibold text-slate-900 dark:text-white">{{ $p['verified_name'] ?? $account->verified_name ?? '—' }}</p>
                    <p class="mt-2 text-xs"><x-badge :color="($p['name_status'] ?? '') === 'APPROVED' ? 'green' : (in_array($p['name_status'] ?? '', ['DECLINED', 'EXPIRED']) ? 'red' : 'amber')">{{ str_replace('_', ' ', strtolower($p['name_status'] ?? 'unknown')) }}</x-badge></p>
                    @if (! empty($p['is_official_business_account']))<p class="mt-2 text-xs text-emerald-600">✔ Official business account (green tick)</p>@endif
                </x-card>
            </div>

            <x-card class="mt-5" title="Details" :padding="false">
                <dl class="grid divide-y divide-slate-100 text-sm dark:divide-slate-800 sm:grid-cols-2 sm:divide-y-0">
                    @foreach ([
                        'Phone verification' => $p['code_verification_status'] ?? null,
                        'Platform' => $p['platform_type'] ?? null,
                        'Throughput' => $p['throughput']['level'] ?? null,
                        'Mode' => $p['account_mode'] ?? null,
                        'Business account (WABA)' => $w['name'] ?? null,
                        'WABA review' => $w['account_review_status'] ?? null,
                        'Billing currency' => $w['currency'] ?? null,
                        'Ownership' => $w['ownership_type'] ?? null,
                    ] as $label => $value)
                        <div class="flex justify-between gap-4 px-5 py-3 sm:border-b sm:border-slate-100 sm:dark:border-slate-800">
                            <dt class="text-slate-500">{{ $label }}</dt>
                            <dd class="text-right font-medium text-slate-800 dark:text-slate-200">{{ $value ? str_replace('_', ' ', $value) : '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-card>
        @endif

        {{-- =========================================================== PROFILE --}}
        @if ($tab === 'profile')
            @if ($profileError)
                <div class="mb-5 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-500/10 dark:text-rose-200">Could not load the profile from Meta: {{ $profileError }}</div>
            @endif
            @php $sites = $profile['websites'] ?? []; @endphp
            <form method="POST" action="{{ route('accounts.manage.profile', $account) }}" enctype="multipart/form-data" x-data="{ saving: false, preview: @js($profile['profile_picture_url'] ?? null) }" @submit="saving = true">
                @csrf @method('PUT')
                <div class="grid gap-6 lg:grid-cols-3">
                    <x-card title="Profile photo" class="lg:col-span-1">
                        <div class="flex flex-col items-center gap-4">
                            <div class="h-32 w-32 overflow-hidden rounded-full border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800">
                                <template x-if="preview"><img :src="preview" class="h-full w-full object-cover" alt="Profile photo"></template>
                            </div>
                            <label class="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                                <x-icon name="upload" class="h-4 w-4" /> Choose photo
                                <input type="file" name="photo" accept="image/png,image/jpeg" class="sr-only" @change="const f = $event.target.files[0]; if (f) preview = URL.createObjectURL(f)">
                            </label>
                            <p class="text-center text-xs text-slate-500">Square JPG/PNG, 640×640 recommended, max 5 MB.</p>
                            @error('photo')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                    </x-card>
                    <x-card title="Business info" class="lg:col-span-2">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label class="form-label" for="about">About <span class="font-normal text-slate-400">(status line, max 139)</span></label>
                                <input id="about" name="about" maxlength="139" class="form-input" value="{{ old('about', $profile['about'] ?? '') }}">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="form-label" for="description">Description</label>
                                <textarea id="description" name="description" rows="3" maxlength="512" class="form-input">{{ old('description', $profile['description'] ?? '') }}</textarea>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="form-label" for="address">Address</label>
                                <input id="address" name="address" maxlength="256" class="form-input" value="{{ old('address', $profile['address'] ?? '') }}">
                            </div>
                            <div>
                                <label class="form-label" for="email">Email</label>
                                <input id="email" name="email" type="email" maxlength="128" class="form-input" value="{{ old('email', $profile['email'] ?? '') }}">
                            </div>
                            <div>
                                <label class="form-label" for="vertical">Category</label>
                                <select id="vertical" name="vertical" class="form-input">
                                    <option value="">—</option>
                                    @foreach ($verticals as $value => $label)
                                        <option value="{{ $value }}" @selected(old('vertical', $profile['vertical'] ?? '') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label" for="website_1">Website</label>
                                <input id="website_1" name="website_1" type="url" class="form-input" value="{{ old('website_1', $sites[0] ?? '') }}" placeholder="https://">
                            </div>
                            <div>
                                <label class="form-label" for="website_2">Second website</label>
                                <input id="website_2" name="website_2" type="url" class="form-input" value="{{ old('website_2', $sites[1] ?? '') }}" placeholder="https://">
                            </div>
                        </div>
                        @if ($errors->any())<p class="form-error mt-3">{{ $errors->first() }}</p>@endif
                    </x-card>
                </div>
                <div class="mt-5 flex justify-end">
                    <x-button ::disabled="saving"><span x-show="saving" x-cloak class="spinner"></span> Save on WhatsApp</x-button>
                </div>
            </form>

            <x-card class="mt-6" title="Meta App ID" subtitle="Only needed to upload the profile photo.">
                @if ($appId)
                    <p class="text-sm text-slate-600 dark:text-slate-400">Using App ID <code class="font-mono">{{ $appId }}</code>.</p>
                @endif
                <form method="POST" action="{{ route('accounts.manage.app-id', $account) }}" class="mt-3 flex flex-wrap gap-2">
                    @csrf
                    <input name="meta_app_id" inputmode="numeric" class="form-input max-w-xs font-mono" value="{{ old('meta_app_id', $account->meta_app_id) }}" placeholder="e.g. 123456789012345">
                    <x-button variant="secondary">Save App ID</x-button>
                </form>
                <p class="form-help">developers.facebook.com → your app → App settings → Basic → App ID. Usually detected automatically.</p>
            </x-card>
        @endif

        {{-- =========================================================== SECURITY --}}
        @if ($tab === 'security')
            <x-card title="Two-step verification PIN" subtitle="A 6-digit PIN Meta asks for whenever this number is registered again. Changing it here updates it on WhatsApp right away.">
                <form method="POST" action="{{ route('accounts.manage.pin', $account) }}" class="grid max-w-md gap-4" autocomplete="off" x-data="{ show: false }">
                    @csrf
                    <div>
                        <label class="form-label" for="pin">New PIN</label>
                        <input id="pin" name="pin" :type="show ? 'text' : 'password'" inputmode="numeric" maxlength="6" pattern="\d{6}" required class="form-input font-mono tracking-[0.4em]" autocomplete="new-password">
                        @error('pin')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="pin_confirmation">Repeat PIN</label>
                        <input id="pin_confirmation" name="pin_confirmation" :type="show ? 'text' : 'password'" inputmode="numeric" maxlength="6" pattern="\d{6}" required class="form-input font-mono tracking-[0.4em]" autocomplete="new-password">
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" x-model="show" class="rounded border-slate-300 text-brand-600"> Show PIN</label>
                    <div><x-button icon="lock">Change PIN</x-button></div>
                </form>
                <p class="mt-4 text-xs text-slate-500">The PIN is sent to Meta only — it is not stored in this app. Write it down somewhere safe.</p>
            </x-card>
        @endif

        {{-- =========================================================== BLOCKED --}}
        @if ($tab === 'blocked')
            <x-card :padding="false" title="Blocked customers" subtitle="Blocked people can't message or call this number. Block someone from their chat or contact page.">
                @forelse ($blocked as $c)
                    <div class="flex items-center gap-3 border-t border-slate-100 px-5 py-3 dark:border-slate-800">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold dark:bg-slate-800">{{ $c->initials() }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">{{ $c->displayName() }}</p>
                            <p class="text-xs text-slate-500">{{ $c->phone }} · blocked {{ $c->blocked_at->diffForHumans() }}</p>
                        </div>
                        <form method="POST" action="{{ route('contacts.unblock', $c) }}">
                            @csrf @method('DELETE')
                            <x-button size="sm" variant="secondary" icon="check">Unblock</x-button>
                        </form>
                    </div>
                @empty
                    <x-empty-state icon="x-circle" title="Nobody is blocked" description="Open a chat → ⋯ → Block, or a contact's page → Block on WhatsApp." />
                @endforelse
            </x-card>
            <div class="mt-4">{{ $blocked->links() }}</div>
        @endif

        {{-- =========================================================== CHARGES --}}
        @if ($tab === 'charges')
            <div class="mb-5 flex flex-wrap items-center gap-3">
                <form method="GET" class="flex items-center gap-2">
                    <input type="month" name="month" value="{{ $month }}" class="form-input w-auto" onchange="this.form.submit()" aria-label="Month">
                </form>
                <a href="{{ route('accounts.manage', [$account, 'charges']).'?'.http_build_query(['month' => $month, 'meta' => 1]) }}" class="ml-auto inline-flex items-center gap-2 rounded-xl border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                    <x-icon name="download" class="h-4 w-4" /> Get Meta's own figures
                </a>
            </div>

            <x-card :padding="false" title="Estimate for {{ $start->format('F Y') }}" subtitle="Meta charges per delivered template message by category. Normal replies inside the 24-hour window are free.">
                <table class="table-base">
                    <thead><tr><th>Type</th><th class="text-right">Messages</th><th class="text-right">Rate ({{ $rates['currency'] }})</th><th class="text-right">Estimate</th></tr></thead>
                    <tbody>
                        @foreach ($categories as $key => $label)
                            <tr>
                                <td>{{ $label }} templates</td>
                                <td class="text-right tabular-nums">{{ number_format($counts[$key]) }}</td>
                                <td class="text-right tabular-nums">{{ rtrim(rtrim(number_format((float) $rates[$key], 4), '0'), '.') ?: '0' }}</td>
                                <td class="text-right font-medium tabular-nums">{{ number_format($counts[$key] * (float) $rates[$key], 2) }}</td>
                            </tr>
                        @endforeach
                        <tr><td>Replies / service messages</td><td class="text-right tabular-nums">{{ number_format($counts['service']) }}</td><td class="text-right text-emerald-600">free</td><td class="text-right">0.00</td></tr>
                        <tr><td class="text-slate-500">Failed (not charged)</td><td class="text-right tabular-nums text-slate-500">{{ number_format($counts['failed']) }}</td><td></td><td></td></tr>
                        <tr class="bg-slate-50 dark:bg-slate-800/50"><td class="font-semibold">Estimated total</td><td></td><td></td><td class="text-right text-lg font-bold tabular-nums">{{ $rates['currency'] }} {{ number_format($estimate, 2) }}</td></tr>
                    </tbody>
                </table>
                <p class="px-5 py-3 text-xs text-slate-500">Approximate: real prices depend on the customer's country, and utility templates sent inside an open 24-hour window are free. Meta bills the payment method in WhatsApp Manager directly.</p>
            </x-card>

            @if ($metaError)
                <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-500/10 dark:text-amber-200">Meta's figures are not available for this account: {{ $metaError }}</div>
            @elseif (is_array($metaPoints))
                <x-card class="mt-5" :padding="false" title="Meta's figures" subtitle="From Meta's pricing analytics for this WhatsApp Business Account.">
                    @if ($metaPoints === [])
                        <p class="px-5 py-4 text-sm text-slate-500">No charged messages reported by Meta for this period.</p>
                    @else
                        <table class="table-base">
                            <thead><tr><th>Category</th><th>Type</th><th class="text-right">Volume</th><th class="text-right">Cost</th></tr></thead>
                            <tbody>
                            @foreach ($metaPoints as $pt)
                                <tr>
                                    <td>{{ ucfirst(strtolower(str_replace('_', ' ', $pt['pricing_category'] ?? '—'))) }}</td>
                                    <td class="text-xs text-slate-500">{{ str_replace('_', ' ', $pt['pricing_type'] ?? '') }}</td>
                                    <td class="text-right tabular-nums">{{ number_format((float) ($pt['volume'] ?? 0)) }}</td>
                                    <td class="text-right font-medium tabular-nums">{{ number_format((float) ($pt['cost'] ?? 0), 2) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </x-card>
            @endif

            <x-card class="mt-5" title="Your rates per message" subtitle="Copy them from Meta's pricing page for your customers' main country.">
                <form method="POST" action="{{ route('accounts.manage.rates', $account) }}" class="grid gap-3 sm:grid-cols-5 sm:items-end">
                    @csrf
                    <div><label class="form-label" for="currency">Currency</label><input id="currency" name="currency" maxlength="3" class="form-input uppercase" value="{{ $rates['currency'] }}"></div>
                    @foreach ($categories as $key => $label)
                        <div><label class="form-label" for="rate_{{ $key }}">{{ $label }}</label><input id="rate_{{ $key }}" name="{{ $key }}" type="number" step="0.0001" min="0" class="form-input" value="{{ $rates[$key] }}"></div>
                    @endforeach
                    <x-button variant="secondary">Save rates</x-button>
                </form>
            </x-card>
        @endif
    </div>
</x-app-layout>
