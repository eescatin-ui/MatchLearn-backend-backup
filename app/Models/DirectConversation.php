<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DirectConversation extends Model
{
    protected $fillable = ['user_one_id', 'user_two_id'];

    public function firstUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_one_id');
    }

    public function secondUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_two_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(StudyMessage::class, 'direct_conversation_id');
    }

}
