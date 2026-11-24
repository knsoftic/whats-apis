<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

/**
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $time = $this->wa_timestamp ?? $this->created_at;

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'account_id' => $this->whatsapp_account_id,
            'wa_message_id' => $this->wa_message_id,
            'direction' => $this->direction,
            'type' => $this->type,
            'body' => $this->body,
            'content' => $this->safeContent(),
            'status' => $this->status,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'timestamp' => $time?->toIso8601String(),
            'sent_by' => ($this->content['followup'] ?? false) ? '⏰ Auto follow-up' : (($this->content['bot_flow_id'] ?? null)
                ? '🤖 Bot · '.($this->content['bot_flow'] ?? 'flow')
                : $this->whenLoaded('sentBy', fn () => $this->sentBy?->name)),
            'reply_to' => $this->whenLoaded('replyTo', fn () => $this->replyTo ? [
                'id' => $this->replyTo->id,
                'direction' => $this->replyTo->direction,
                'preview' => $this->replyTo->previewText(),
            ] : null),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type,
                'mime_type' => $a->mime_type,
                'file_name' => $a->file_name,
                'size' => $a->humanSize(),
                'status' => $a->download_status,
                'error' => $a->download_error,
                'url' => $a->isAvailable() && Route::has('media.show') ? route('media.show', $a) : null,
                'download_url' => $a->isAvailable() && Route::has('media.show') ? route('media.show', ['attachment' => $a, 'download' => 1]) : null,
            ])->values()),
        ];
    }

    /**
     * Only the parts of `content` the UI needs.
     *
     * @return array<string, mixed>|null
     */
    private function safeContent(): ?array
    {
        $c = $this->content;

        if (! is_array($c)) {
            return null;
        }

        return match ($this->type) {
            'location' => array_intersect_key($c, array_flip(['latitude', 'longitude', 'name', 'address', 'url'])),
            'contacts' => ['contacts' => collect($c['contacts'] ?? [])->map(fn ($card) => [
                'name' => $card['name']['formatted_name'] ?? null,
                'phones' => collect($card['phones'] ?? [])->pluck('phone')->filter()->values(),
            ])->values()],
            'reaction' => ['emoji' => $c['emoji'] ?? null, 'message_id' => $c['message_id'] ?? null],
            'template' => ['name' => $c['name'] ?? null, 'language' => $c['language'] ?? null],
            'audio', 'document', 'sticker', 'image', 'video' => array_intersect_key($c, array_flip(['filename', 'voice', 'animated'])),
            'interactive' => array_filter([
                'kind' => $c['kind'] ?? null,
                // Outgoing bot menu: show the option titles under the bubble.
                'options' => isset($c['interactive']) ? array_values(array_filter(array_merge(
                    array_map(fn ($b) => $b['reply']['title'] ?? null, $c['interactive']['action']['buttons'] ?? []),
                    array_map(fn ($r) => $r['title'] ?? null, $c['interactive']['action']['sections'][0]['rows'] ?? []),
                ))) : null,
            ]),
            default => null,
        };
    }
}
