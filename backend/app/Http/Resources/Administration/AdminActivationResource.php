<?php

declare(strict_types=1);

namespace App\Http\Resources\Administration;

use App\Support\Http\RequestId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminActivationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['result' => $this->resource['result'] ?? 'admin_activated'];
    }

    public function with(Request $request): array
    {
        return ['meta' => ['request_id' => RequestId::get($request)]];
    }
}
