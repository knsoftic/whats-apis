<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $contact = $this->contact;

        return [
            'id' => $this->id,
            'account_id' => $this->whatsapp_account_id,
            'status' => $this->status,
            'unread_count' => (int) $this->unread_count,
            'last_message_preview' => $this->last_message_preview,
            'last_message_direction' => $this->last_message_direction,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'within_window' => $this->isWithinServiceWindow(),
            'window_expires_at' => $this->serviceWindowExpiresAt()?->toIso8601String(),
            'contact' => $contact ? [
                'id' => $contact->id,
                'name' => $contact->displayName(),
                'phone' => $contact->phone,
                'wa_id' => $contact->wa_id,
                'initials' => $contact->initials(),
                'profile_name' => $contact->profile_name,
                'opt_in_status' => $contact->opt_in_status,
            ] : null,
        ];
    }
}
