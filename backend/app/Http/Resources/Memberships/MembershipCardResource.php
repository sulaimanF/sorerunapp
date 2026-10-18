<?php

declare(strict_types=1);

namespace App\Http\Resources\Memberships;

use App\Support\Http\RequestId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'community_name' => $this->resource['community_name'], 'full_name' => $this->resource['full_name'],
            'member_number' => $this->resource['member_number'], 'qr_payload' => $this->resource['qr_payload'],
            'issued_at' => $this->resource['issued_at'],
        ];
    }

    public function with(Request $request): array
    {
        return ['meta' => ['request_id' => RequestId::get($request)]];
    }
}
