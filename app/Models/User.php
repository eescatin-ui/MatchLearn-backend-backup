<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'has_password',
        'is_suspended',
        'is_admin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'has_password' => 'boolean',
            'is_suspended' => 'boolean',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * Get the user's profile.
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * Get the user's courses.
     */
    public function courses()
    {
        return $this->belongsToMany(Course::class, 'user_courses');
    }

    /**
     * Get the user's availability slots.
     */
    public function availabilities(): HasMany
    {
        return $this->hasMany(Availability::class);
    }

    /**
     * Get the user's selected strengths.
     */
    public function strengths()
    {
        return $this->belongsToMany(Strength::class, 'user_strengths');
    }

    /**
     * Get the user's selected weaknesses.
     */
    public function weaknesses()
    {
        return $this->belongsToMany(Weakness::class, 'user_weaknesses');
    }

    /**
     * Get matches generated for this user.
     */
    public function matches(): HasMany
    {
        return $this->hasMany(StudyMatch::class, 'user_id');
    }

    /**
     * Get groups created by this user.
     */
    public function groups(): HasMany
    {
        return $this->hasMany(StudyGroup::class, 'created_by');
    }

    public function studyGroups(): BelongsToMany
    {
        return $this->belongsToMany(StudyGroup::class, 'study_group_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Get messages sent by this user.
     */
    public function studyMessages(): HasMany
    {
        return $this->hasMany(StudyMessage::class, 'user_id');
    }

    /**
     * Get match records where this user was the matched student.
     */
    public function matchedMatches(): HasMany
    {
        return $this->hasMany(StudyMatch::class, 'matched_user_id');
    }

    public function buddyReviewsReceived(): HasMany
    {
        return $this->hasMany(BuddyReview::class, 'reviewed_user_id');
    }

    public function directConversationsAsFirstUser(): HasMany
    {
        return $this->hasMany(DirectConversation::class, 'user_one_id');
    }

    public function directConversationsAsSecondUser(): HasMany
    {
        return $this->hasMany(DirectConversation::class, 'user_two_id');
    }
}
