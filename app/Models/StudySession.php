<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudySession extends Model
{
    protected $fillable = [
        'host_user_id',
        'participant_user_id',
        'study_group_id',
        'topic',
        'starts_at',
        'ends_at',
        'location',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant_user_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'study_group_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(StudySessionReview::class);
    }
}
