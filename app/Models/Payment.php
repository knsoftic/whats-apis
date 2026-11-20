<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const EXPIRED = 'expired';
    public const CANCELLED = 'cancelled';
    public const REJECTED = 'rejected';

    public const GATEWAY_PKPAY = 'pkpay';
    public const GATEWAY_MANUAL = 'manual';
    public const GATEWAY_FREE = 'free';

    protected $fillable = [
        'user_id', 'plan_id', 'plan_name', 'gateway', 'reference', 'gateway_payment_id', 'gateway_transaction_id',
        'amount', 'currency', 'status', 'checkout_url', 'expires_at', 'paid_at', 'customer_note', 'admin_note',
        'reviewed_by', 'meta',
    ];

    protected $hidden = ['meta'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function amountLabel(): string
    {
        return $this->currency.' '.number_format((float) $this->amount, 2);
    }

    public function gatewayLabel(): string
    {
        return match ($this->gateway) {
            self::GATEWAY_PKPAY => 'PKPay (Raast QR)',
            self::GATEWAY_MANUAL => 'Manual / bank transfer',
            self::GATEWAY_FREE => 'Free plan',
            default => $this->gateway,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::PAID => 'green',
            self::PENDING => 'amber',
            self::REJECTED, self::FAILED => 'red',
            default => 'slate',
        };
    }
}
