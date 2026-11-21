<?php

declare(strict_types=1);

namespace App\Actions\Administration;

use App\Enums\RoleCode;
use App\Models\User;
use App\Services\Administration\ProvisioningAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ListAdminAccounts
{
    public function handle(int $actorId, int $version, int $page): LengthAwarePaginator
    {
        return DB::transaction(function () use ($actorId, $version, $page): LengthAwarePaginator {
            app(ProvisioningAccess::class)->lock($actorId, $version);

            // Include legacy/multi-role Admins without inventing invitation or member data.
            return User::query()->whereHas('roles', fn ($query) => $query->where('code', RoleCode::Admin->value))
                ->leftJoin('admin_provisionings', 'admin_provisionings.user_id', '=', 'users.id')
                ->select(['users.id', 'users.name', 'users.email', 'users.status', 'users.email_verified_at', 'admin_provisionings.user_id as provisioned_user_id', 'admin_provisionings.activated_at as provisioned_activated_at'])
                ->withExists(['membership', 'memberProfile', 'roles as has_other_roles' => fn ($query) => $query->where('code', '!=', RoleCode::Admin->value), 'roles as has_super_role' => fn ($query) => $query->where('code', RoleCode::SuperAdmin->value)])
                ->orderByDesc('users.id')->paginate(20, ['*'], 'page', $page);
        }, 3);
    }
}
