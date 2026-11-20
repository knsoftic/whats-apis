@php
    use App\Support\Permissions as P;

    $nav = [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'active' => 'dashboard', 'icon' => 'home', 'can' => P::DASHBOARD_VIEW],
        ['label' => 'Chats', 'route' => 'chat.index', 'active' => 'chat.*', 'icon' => 'chat', 'can' => P::CHATS_VIEW],
        ['label' => 'Contacts', 'route' => 'contacts.index', 'active' => 'contacts.*', 'icon' => 'contacts', 'can' => P::CONTACTS_VIEW],
        ['label' => 'Message Templates', 'route' => 'templates.index', 'active' => 'templates.*', 'icon' => 'template', 'can' => P::TEMPLATES_VIEW],
        ['label' => 'Bot Flows', 'route' => 'bots.index', 'active' => 'bots.*', 'icon' => 'bolt', 'can' => P::BOTS_MANAGE],
        ['label' => 'WhatsApp Numbers', 'route' => 'accounts.index', 'active' => 'accounts.*', 'icon' => 'phone', 'can' => P::ACCOUNTS_MANAGE, 'customer_only' => true],
        ['label' => 'Billing & Plan', 'route' => 'billing.index', 'active' => 'billing.*', 'icon' => 'chart', 'can' => P::DASHBOARD_VIEW, 'customer_only' => true, 'role' => P::ROLE_CUSTOMER],
    ];
    $admin = [
        ['label' => 'WhatsApp Accounts', 'route' => 'accounts.index', 'active' => 'accounts.*', 'icon' => 'phone', 'can' => P::ACCOUNTS_MANAGE],
        ['label' => 'Users', 'route' => 'users.index', 'active' => 'users.*', 'icon' => 'users', 'can' => P::USERS_MANAGE],
        ['label' => 'Plans', 'route' => 'plans.index', 'active' => 'plans.*', 'icon' => 'chart', 'can' => P::PLANS_MANAGE],
        ['label' => 'Payments', 'route' => 'payments.index', 'active' => 'payments.*', 'icon' => 'inbox', 'can' => P::PLANS_MANAGE],
        ['label' => 'Activity Logs', 'route' => 'activity-logs.index', 'active' => 'activity-logs.*', 'icon' => 'activity', 'can' => P::ACTIVITY_VIEW],
        ['label' => 'Landing Page', 'route' => 'settings.landing', 'active' => 'settings.landing*', 'icon' => 'photo', 'can' => P::SETTINGS_MANAGE],
        ['label' => 'Contact Messages', 'route' => 'contact-messages.index', 'active' => 'contact-messages.*', 'icon' => 'envelope', 'can' => P::SETTINGS_MANAGE, 'badge' => 'contact'],
        ['label' => 'Settings', 'route' => 'settings.index', 'active' => 'settings.*', 'except' => 'settings.landing*', 'icon' => 'key', 'can' => P::SETTINGS_MANAGE],
    ];
    $isCustomer = auth()->user()->isPlanLimited();
    $visible = fn ($items) => array_filter($items, fn ($i) => Route::has($i['route'])
        && auth()->user()->can($i['can'])
        && (! ($i['customer_only'] ?? false) || $isCustomer)
        && (! isset($i['role']) || auth()->user()->hasRole($i['role'])));
    $nav = $visible($nav);
    // Customers manage their own numbers from the main menu; the Administration block is for staff.
    $admin = $isCustomer ? [] : $visible($admin);
    $badges = ['contact' => collect($admin)->contains('badge', 'contact') ? \App\Models\ContactMessage::unread()->count() : 0];
@endphp

<div class="flex h-16 shrink-0 items-center gap-3 border-b border-slate-200 px-5 dark:border-slate-800">
    <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-3">
        <x-application-logo class="h-9 w-9 shrink-0" />
        <span class="min-w-0 leading-tight">
            <span class="block truncate text-sm font-bold text-slate-900 dark:text-white">{{ $brand->siteName() }}</span>
            <span class="block truncate text-[11px] font-medium text-slate-500 dark:text-slate-400">{{ $brand->tagline() }}</span>
        </span>
    </a>
    <button type="button" @click="sidebarOpen = false" class="ml-auto rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 lg:hidden" aria-label="Close menu">
        <x-icon name="x" />
    </button>
</div>

<nav class="flex-1 space-y-6 overflow-y-auto px-3 py-5">
    <div class="space-y-1">
        @foreach ($nav as $item)
            @php $active = request()->routeIs($item['active']); @endphp
            <a href="{{ route($item['route']) }}" @class([
                'group flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition',
                'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' => $active,
                'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white' => ! $active,
            ])>
                <x-icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
                <span class="truncate">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </div>

    @if ($admin)
        <div>
            <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">Administration</p>
            <div class="space-y-1">
                @foreach ($admin as $item)
                    @php $active = request()->routeIs($item['active']) && ! (isset($item['except']) && request()->routeIs($item['except'])); @endphp
                    <a href="{{ route($item['route']) }}" @class([
                        'group flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition',
                        'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' => $active,
                        'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white' => ! $active,
                    ])>
                        <x-icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
                        <span class="truncate">{{ $item['label'] }}</span>
                        @if (! empty($badges[$item['badge'] ?? '']))
                            <span class="ml-auto rounded-full bg-brand-600 px-1.5 py-0.5 text-[10px] font-bold leading-none text-white">{{ $badges[$item['badge']] > 99 ? '99+' : $badges[$item['badge']] }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</nav>

<div class="border-t border-slate-200 px-5 py-4 text-[11px] text-slate-400 dark:border-slate-800 dark:text-slate-500">
    Official Meta WhatsApp Cloud API<br>© {{ date('Y') }} {{ $brand->siteName() }}
    <span class="mt-1 block"><a href="{{ route('legal.privacy') }}" class="hover:text-slate-600 dark:hover:text-slate-300">Privacy</a> · <a href="{{ route('legal.terms') }}" class="hover:text-slate-600 dark:hover:text-slate-300">Terms</a></span>
</div>
