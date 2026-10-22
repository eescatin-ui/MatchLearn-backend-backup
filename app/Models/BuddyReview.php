<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuddyReview extends Model
{
    protected $fillable = [
        'reviewer_user_id',
        'reviewed_user_id',
        'rating',
        'comment',
    ];

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function reviewedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_user_id');
    }
}
