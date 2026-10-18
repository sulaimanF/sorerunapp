<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Notifications\EnqueueNotificationDelivery;
use App\Models\NotificationDelivery;
use App\Services\Notifications\DeliverNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class DispatchNotificationOutbox extends Command
{
    protected $signature = 'outbox:dispatch {--limit=100 : Maximum eligible rows per sweep}';

    protected $description = 'Enqueue due outbox rows and recover expired delivery leases';

    public function handle(EnqueueNotificationDelivery $enqueue): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('limit must be an integer from 1 to 1000.');

            return self::INVALID;
        }
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        $ids = NotificationDelivery::query()->where(function (Builder $query) use ($now): void {
            $query->where(function (Builder $due) use ($now): void {
                $due->whereIn('status', ['pending', 'failed'])->where('attempt_count', '<', DeliverNotification::MAX_ATTEMPTS)->where('available_at', '<=', $now);
            })->orWhere(function (Builder $expired) use ($now): void {
                $expired->where('status', 'processing')->where(function (Builder $lease) use ($now): void {
                    $lease->whereNull('lease_until')->orWhere('lease_until', '<=', $now);
                });
            });
        })->orderBy('id')->limit($limit)->pluck('id');
        $queued = 0;
        foreach ($ids as $id) {
            $queued += (int) $enqueue->handle((int) $id);
        }
        $this->info('Outbox jobs enqueued: '.$queued.'. Duplicate jobs are guarded by delivery leases.');

        return self::SUCCESS;
    }
}
