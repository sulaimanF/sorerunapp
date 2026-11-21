<?php

declare(strict_types=1);

namespace App\Http\Resources\Administration;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Support\Http\RequestId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminAccountListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'items' => $this->resource->getCollection()->map(fn (User $user) => [
                'user_id' => (int) $user->id, 'full_name' => $user->name, 'email' => $user->email,
                'account_status' => $user->status->value, 'email_verified' => $user->email_verified_at !== null,
                'has_invitation' => $user->provisioned_user_id !== null,
                'can_delete' => (int) $user->id !== (int) $request->user('web')->id && ! $user->has_other_roles && ! $user->membership_exists && ! $user->member_profile_exists,
                'can_change_password' => (int) $user->id !== (int) $request->user('web')->id && ! $user->has_super_role
                    && $user->status === AccountStatus::Enabled && $user->email_verified_at !== null
                    && ($user->provisioned_user_id === null || $user->provisioned_activated_at !== null),
            ])->all(),
            'pagination' => [
                'current_page' => $this->resource->currentPage(), 'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(), 'total' => $this->resource->total(),
            ],
        ];
    }

    public function with(Request $request): array
    {
        return ['meta' => ['request_id' => RequestId::get($request)]];
    }
}
