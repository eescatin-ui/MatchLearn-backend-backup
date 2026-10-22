<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationReadState extends Model
{
    protected $fillable = [
        'user_id',
        'conversation_type',
        'conversation_id',
        'last_read_message_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
