<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudyGroupJoinRequest extends Model
{
    protected $fillable = [
        'study_group_id',
        'user_id',
        'invited_by_user_id',
        'status',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'study_group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
