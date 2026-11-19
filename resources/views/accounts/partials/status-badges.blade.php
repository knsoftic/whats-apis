@php
    $compact = $compact ?? false;
    $api = match ($account->api_status) {
        'connected' => ['green', 'API connected'],
        'failed' => ['red', 'API failed'],
        default => ['slate', 'API not checked'],
    };
    $webhook = $account->isWebhookVerified() ? ['green', 'Webhook verified'] : ['amber', 'Webhook not verified'];
@endphp
<div class="flex flex-wrap items-center gap-1.5">
    @unless ($account->is_active)
        <x-badge color="slate" dot>Disabled</x-badge>
    @endunless
    <x-badge :color="$api[0]" dot :title="$account->api_status_message">{{ $api[1] }}</x-badge>
    <x-badge :color="$webhook[0]" dot>{{ $webhook[1] }}</x-badge>
    @unless ($compact)
        @php
            $sub = match ($account->subscription_status) {
                'subscribed' => ['green', 'App subscribed'],
                'not_subscribed' => ['amber', 'App not subscribed'],
                'failed' => ['red', 'Subscription check failed'],
                default => ['slate', 'Subscription unknown'],
            };
        @endphp
        <x-badge :color="$sub[0]" dot :title="$account->subscription_status_message">{{ $sub[1] }}</x-badge>
    @endunless
</div>
