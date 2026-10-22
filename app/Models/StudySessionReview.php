<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudySessionReview extends Model
{
    protected $fillable = [
        'study_session_id',
        'reviewer_user_id',
        'reviewed_user_id',
        'rating',
        'comment',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(StudySession::class, 'study_session_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function reviewedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_user_id');
    }
}
