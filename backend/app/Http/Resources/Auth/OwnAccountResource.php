<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\Support\Http\RequestId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OwnAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_intersect_key($this->resource, array_flip(['full_name', 'email', 'email_verified', 'email_change']));
    }

    public function with(Request $request): array
    {
        return ['meta' => ['request_id' => RequestId::get($request)]];
    }
}
