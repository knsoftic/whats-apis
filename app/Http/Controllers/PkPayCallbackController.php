<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Payments\PaymentService;
use App\Services\Payments\PkPayClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/payments/pkpay/callback — PKPay status-change callback.
 * Verified with X-Signature = HMAC-SHA256(X-Timestamp "." raw body, API secret).
 */
class PkPayCallbackController extends Controller
{
    public function __invoke(Request $request, PkPayClient $client, PaymentService $payments): Response
    {
        $raw = $request->getContent();

        if (! $client->verifyCallback($raw, $request->header('X-Timestamp'), $request->header('X-Signature'))) {
            Log::warning('PKPay callback rejected: invalid signature', ['ip' => $request->ip()]);

            return response('Invalid signature', 401);
        }

        $payload = json_decode($raw, true);
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : null;

        if (! $data) {
            return response('Invalid payload', 400);
        }

        // Sandbox / portal test callbacks must never activate a plan.
        if (($data['sandbox'] ?? false) === true || ($payload['sandbox'] ?? false) === true || strtolower((string) $request->header('X-Sandbox')) === 'true') {
            Log::info('PKPay sandbox callback acknowledged (ignored)', ['order_id' => $data['order_id'] ?? null]);

            return response('SANDBOX_IGNORED', 200);
        }

        $payment = Payment::where('gateway', Payment::GATEWAY_PKPAY)
            ->where(function ($q) use ($data) {
                $q->where('reference', (string) ($data['order_id'] ?? ''))
                    ->orWhere(fn ($w) => $w->whereNotNull('gateway_payment_id')->where('gateway_payment_id', (string) ($data['payment_id'] ?? '')));
            })
            ->first();

        if (! $payment) {
            Log::info('PKPay callback for an unknown order', ['order_id' => $data['order_id'] ?? null]);

            return response('UNKNOWN_ORDER', 200);
        }

        $payments->applyPkPayData($payment, $data, 'callback');

        return response('OK', 200);
    }
}
