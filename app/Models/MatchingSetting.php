<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatchingSetting extends Model
{
    public const DEFAULT_WEIGHTS = [
        'courses' => 25,
        'academic_complementarity' => 25,
        'program' => 20,
        'learning_style' => 10,
        'schedule' => 10,
        'year_level' => 5,
        'study_mode' => 5,
    ];

    protected $fillable = ['key', 'weights'];

    protected function casts(): array
    {
        return ['weights' => 'array'];
    }
}
