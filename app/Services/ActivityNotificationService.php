<?php

namespace App\Services;

use App\Models\ActivityNotification;
use App\Models\User;

class ActivityNotificationService
{
    public function send(
        int $userId,
        string $eventKey,
        string $type,
        string $title,
        string $message,
        array $data = [],
    ): void {
        ActivityNotification::query()->firstOrCreate(
            ['user_id' => $userId, 'event_key' => $eventKey],
            [
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => $data,
            ]
        );
    }

    public function sendToUsers(
        iterable $users,
        string $eventKey,
        string $type,
        string $title,
        string $message,
        array $data = [],
        ?int $exceptUserId = null,
    ): void {
        foreach ($users as $user) {
            $userId = $user instanceof User ? $user->id : (int) $user;
            if ($exceptUserId !== null && $userId === $exceptUserId) {
                continue;
            }

            $this->send($userId, $eventKey.':'.$userId, $type, $title, $message, $data);
        }
    }
}
