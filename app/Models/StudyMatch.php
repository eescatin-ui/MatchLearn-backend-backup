<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudyMatch extends Model
{
    use HasFactory;

    protected $table = 'matches';

    protected $fillable = [
        'user_id',
        'matched_user_id',
        'compatibility_score',
        'shared_course_count',
        'reasons',
    ];

    protected $casts = [
        'reasons' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function matchedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_user_id');
    }
}
