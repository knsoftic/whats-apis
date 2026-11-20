<?php

namespace App\Http\Controllers;

use App\Exceptions\EmbeddedSignupException;
use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\EmbeddedSignupService;
use App\Support\MetaPlatform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Connect with Meta" — WhatsApp Embedded Signup for Tech Providers.
 * Manual entry (WhatsAppAccountController@create) stays available.
 */
class EmbeddedSignupController extends Controller
{
    public function __construct(
        private readonly EmbeddedSignupService $service,
        private readonly MetaPlatform $platform,
    ) {}

    public function show(): View
    {
        $this->authorize('create', WhatsAppAccount::class);

        return view('accounts.connect', [
            'platform' => $this->platform,
            'suggestedPin' => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', WhatsAppAccount::class);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:4096'],
            'event' => ['nullable', 'string', 'max:60'],
            'waba_id' => ['nullable', 'regex:/^\d{5,30}$/'],
            'phone_number_id' => ['nullable', 'regex:/^\d{5,30}$/'],
            'business_id' => ['nullable', 'regex:/^\d{5,30}$/'],
            'name' => ['nullable', 'string', 'max:120'],
            'pin' => ['nullable', 'digits:6'],
        ]);

        try {
            $result = $this->service->complete($data, $request->user());
        } catch (EmbeddedSignupException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $account = $result['account'];

        session()->flash($result['warnings'] ? 'warning' : 'success', $result['warnings']
            ? 'Number connected, with notes: '.implode(' ', $result['warnings'])
            : "WhatsApp number {$account->label()} connected with Meta Embedded Signup.");

        return response()->json([
            'ok' => true,
            'created' => $result['created'],
            'account_id' => $account->id,
            'warnings' => $result['warnings'],
            'redirect' => route('accounts.show', $account),
        ]);
    }

    public function register(Request $request, WhatsAppAccount $account): RedirectResponse
    {
        $this->authorize('update', $account);

        $data = $request->validate(['pin' => ['required', 'digits:6']]);

        [$status, $message] = $this->service->registerExisting($account, $data['pin']);

        return back()->with($status === 'registered' ? 'success' : 'error', $message);
    }
}
