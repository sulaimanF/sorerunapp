<?php

declare(strict_types=1);

namespace App\Http\Resources\Members;

use App\Support\Http\RequestId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'full_name' => $this->resource->full_name,
            'birth_date' => $this->resource->birth_date?->format('Y-m-d'),
            'phone' => $this->resource->phone, 'city' => $this->resource->city,
            'gender' => $this->resource->gender, 'profile_complete' => $this->resource->isComplete(),
            'updated_at' => $this->resource->updated_at?->utc()->toIso8601ZuluString(),
        ];
    }

    public function with(Request $request): array
    {
        return ['meta' => ['request_id' => RequestId::get($request)]];
    }
}
