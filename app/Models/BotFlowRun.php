<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotFlowRun extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['bot_flow_id', 'conversation_id', 'trigger_message_id'];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(BotFlow::class, 'bot_flow_id');
    }
}
