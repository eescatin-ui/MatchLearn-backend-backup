<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Profile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'student_id',
        'gender',
        'marital_status',
        'phone_number',
        'phone_number_public',
        'address',
        'address_public',
        'institution',
        'department_id',
        'program_id',
        'year_level',
        'learning_style_id',
        'bio',
        'profile_photo_url',
        'profile_photo_data',
        'profile_photo_mime_type',
        'study_topics',
        'academic_strengths',
        'academic_needs',
        'study_mode',
    ];

    protected function casts(): array
    {
        return [
            'study_topics' => 'array',
            'academic_strengths' => 'array',
            'academic_needs' => 'array',
            'phone_number_public' => 'boolean',
            'address_public' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function learningStyle(): BelongsTo
    {
        return $this->belongsTo(LearningStyle::class);
    }

    public function studyCourses(): HasMany
    {
        return $this->hasMany(StudyCourse::class)->orderBy('id');
    }
}
