<?php

declare(strict_types=1);

namespace App\Http\Resources\Administration;

use App\Support\Http\RequestId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_intersect_key($this->resource, array_flip([
            'user_id', 'full_name', 'email', 'role', 'status', 'expires_at', 'retry_after', 'activated_at',
        ]));
    }

    public function with(Request $request): array
    {
        return ['meta' => ['request_id' => RequestId::get($request)]];
    }
}
