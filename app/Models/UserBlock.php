<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBlock extends Model
{
    protected $fillable = [
        'blocker_user_id',
        'blocked_user_id',
    ];

    public function blockedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_user_id');
    }

    public static function blocksEitherDirection(int $firstUserId, int $secondUserId): bool
    {
        return self::query()
            ->where(fn ($query) => $query
                ->where('blocker_user_id', $firstUserId)
                ->where('blocked_user_id', $secondUserId))
            ->orWhere(fn ($query) => $query
                ->where('blocker_user_id', $secondUserId)
                ->where('blocked_user_id', $firstUserId))
            ->exists();
    }
}
