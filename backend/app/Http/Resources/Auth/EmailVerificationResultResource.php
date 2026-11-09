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
        return ['result' => match ($this->resource['result']) {
            'membership_activated' => 'email_verified',
            'already_active' => 'already_verified',
            'email_verified_profile_incomplete' => 'email_verified_profile_incomplete',
        }];
    }

    public function with(Request $request): array
    {
        return ['meta' => ['request_id' => RequestId::get($request)]];
    }
}
