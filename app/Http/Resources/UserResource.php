<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_suspended' => $this->is_suspended,
            'profile_photo_url' => $this->relationLoaded('profile')
                ? $this->getRelation('profile')?->profile_photo_url
                : $this->profile()->value('profile_photo_url'),
            'profile' => ProfileResource::make($this->whenLoaded('profile')),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
