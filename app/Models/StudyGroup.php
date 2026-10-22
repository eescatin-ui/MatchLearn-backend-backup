<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudyGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'created_by',
        'name',
        'course',
        'schedule',
        'description',
        'avatar_icon',
        'avatar_color',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(StudyMessage::class, 'group_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'study_group_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function joinRequests(): HasMany
    {
        return $this->hasMany(StudyGroupJoinRequest::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(StudyGroupReview::class);
    }
}
