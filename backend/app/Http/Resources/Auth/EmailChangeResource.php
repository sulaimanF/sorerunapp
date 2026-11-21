<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\Support\Http\RequestId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['result' => $this->resource];
    }

    public function with(Request $request): array
    {
        return ['meta' => ['request_id' => RequestId::get($request)]];
    }
}
