<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudyCourse extends Model
{
    protected $table = 'profile_study_courses';

    protected $fillable = [
        'course_code',
        'course_code_normalized',
        'course_name',
    ];

    public static function normalizeCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', self::extractCode($code)));
    }

    public static function extractCode(string $value): string
    {
        return trim(preg_split('/\s+-\s+/', trim($value), 2)[0] ?? '');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
