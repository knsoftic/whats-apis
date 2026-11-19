<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageTemplate extends Model
{
    use HasFactory;

    public const APPROVED = 'APPROVED';

    protected $fillable = [
        'whatsapp_account_id',
        'meta_template_id',
        'name',
        'language',
        'category',
        'status',
        'parameter_format',
        'components',
        'rejected_reason',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'components' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function isApproved(): bool
    {
        return strtoupper((string) $this->status) === self::APPROVED;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function component(string $type): ?array
    {
        foreach ($this->components ?? [] as $component) {
            if (strtoupper($component['type'] ?? '') === strtoupper($type)) {
                return $component;
            }
        }

        return null;
    }

    public function header(): ?array
    {
        return $this->component('HEADER');
    }

    public function body(): ?array
    {
        return $this->component('BODY');
    }

    public function footer(): ?array
    {
        return $this->component('FOOTER');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buttons(): array
    {
        return $this->component('BUTTONS')['buttons'] ?? [];
    }

    /**
     * Variable placeholders in a text, e.g. {{1}} / {{customer_name}}.
     *
     * @return array<int, string>
     */
    public static function placeholders(?string $text): array
    {
        if (! $text) {
            return [];
        }

        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $text, $m);

        return array_values(array_unique($m[1]));
    }

    public function headerFormat(): ?string
    {
        $header = $this->header();

        return $header ? strtoupper($header['format'] ?? 'TEXT') : null;
    }

    /**
     * @return array<int, string>
     */
    public function headerPlaceholders(): array
    {
        return $this->headerFormat() === 'TEXT' ? self::placeholders($this->header()['text'] ?? null) : [];
    }

    /**
     * @return array<int, string>
     */
    public function bodyPlaceholders(): array
    {
        return self::placeholders($this->body()['text'] ?? null);
    }

    /**
     * URL buttons with a dynamic suffix ({{1}}) need one parameter each.
     *
     * @return array<int, array{index:int, text:string, url:string}>
     */
    public function dynamicUrlButtons(): array
    {
        $result = [];

        foreach ($this->buttons() as $index => $button) {
            if (strtoupper($button['type'] ?? '') === 'URL' && self::placeholders($button['url'] ?? '') !== []) {
                $result[] = ['index' => $index, 'text' => $button['text'] ?? '', 'url' => $button['url']];
            }
        }

        return $result;
    }
}
