<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EmailChangeRequest;
use Illuminate\Console\Command;

class PruneEmailChangeRequests extends Command
{
    protected $signature = 'email-changes:prune';

    protected $description = 'Remove email change proofs expired more than 30 days ago, in bounded batches';

    public function handle(): int
    {
        $ids = EmailChangeRequest::query()->where('expires_at', '<', now('UTC')->subDays(30))
            ->orderBy('expires_at')->limit(1000)->pluck('id');
        $count = EmailChangeRequest::query()->whereKey($ids)->delete();
        $this->info('Expired email change requests removed: '.$count);

        return self::SUCCESS;
    }
}
