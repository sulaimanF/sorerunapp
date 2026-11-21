<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AdminProvisioning;
use Illuminate\Support\Facades\DB;

trait AdministrationCommittedFixtures
{
    use AdministrationFixtures;

    private array $administrationActors = [];

    private function cleanupAdministrationFixtures(): void
    {
        if ($this->administrationActors === []) {
            return;
        }
        $targets = AdminProvisioning::query()->whereIn('created_by', $this->administrationActors)->pluck('user_id')->all();
        $ids = array_values(array_unique([...$this->administrationActors, ...$targets]));
        DB::transaction(function () use ($ids): void {
            // Only rows owned by this test; never reset singleton landing data or existing users.
            DB::table('security_bootstraps')->where('name', 'first_super_admin')->whereIn('user_id', $ids)->update(['user_id' => null, 'completed_at' => null]);
            DB::table('notification_deliveries')->whereIn('user_id', $ids)->delete();
            DB::table('admin_invitations')->whereIn('user_id', $ids)->delete();
            DB::table('admin_provisionings')->whereIn('user_id', $ids)->delete();
            DB::table('password_reset_tokens')->whereIn('user_id', $ids)->delete();
            DB::table('sessions')->whereIn('user_id', $ids)->delete();
            DB::table('audit_logs')->where(function ($query) use ($ids): void {
                $query->whereIn('actor_user_id', $ids)->orWhere(function ($targets) use ($ids): void {
                    $targets->where('target_type', 'user')->whereIn('target_id', $ids);
                });
            })->delete();
            DB::table('user_roles')->whereIn('user_id', $ids)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
        });
    }
}
