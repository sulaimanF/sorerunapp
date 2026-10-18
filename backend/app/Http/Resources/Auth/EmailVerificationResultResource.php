<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\Support\Http\RequestId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailVerificationResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['result' => $this->resource['result'], 'membership' => [
            'status' => $this->resource['membership']['status'],
            'member_number' => $this->resource['membership']['member_number'],
        ]];
    }

    public function with(Request $request): array
    {
        return ['meta' => ['request_id' => RequestId::get($request)]];
    }
}
