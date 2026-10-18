<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\Support\Http\RequestId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrowserSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['memberProfile', 'membership', 'roles']);
        $membership = $this->resource->membership;

        return [
            'user' => [
                'id' => $this->resource->id,
                'full_name' => $this->resource->memberProfile?->full_name ?? $this->resource->name,
                'email' => $this->resource->email,
                'email_verified' => $this->resource->hasVerifiedEmail(),
            ],
            'roles' => $this->resource->roles->map(fn ($role): string => $role->code->value)->sort()->values()->all(),
            'profile_complete' => $this->resource->memberProfile?->isComplete() ?? false,
            'membership' => $membership === null ? null : [
                'status' => $membership->status->value,
                'member_number' => $membership->member_number,
                'joined_at' => $membership->joined_at?->utc()->toIso8601ZuluString(),
            ],
        ];
    }

    public function with(Request $request): array
    {
        return ['meta' => ['request_id' => RequestId::get($request)]];
    }
}
