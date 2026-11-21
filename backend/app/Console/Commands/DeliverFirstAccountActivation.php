<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Notifications\EnqueueNotificationDelivery;
use App\Jobs\DeliverOutboxNotification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Administration\FirstAccountActivation;
use App\Services\Administration\InitialActivationEnvironment;
use App\Services\Notifications\LocalMailScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DeliverFirstAccountActivation extends Command
{
    protected $signature = 'admin:deliver-first-activation {--user-id=} {--email=}';

    protected $description = 'Drain only the bound initial activation queue to loopback Mailpit, never other mail';

    public function handle(InitialActivationEnvironment $environment): int
    {
        $id = filter_var($this->option('user-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $email = $this->option('email');
        if ($id === false || ! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('ID akun dan email eksplisit wajib diberikan.');

            return self::INVALID;
        }
        try {
            $environment->assertReady();
            app(LocalMailScope::class)->assertTransport();
            $user = User::find($id);
            $bound = DB::table('security_bootstraps')->where('name', 'first_super_admin')->value('user_id');
            if ($user === null || (int) $bound !== $id || ! hash_equals($user->email, mb_strtolower(trim($email), 'UTF-8'))) {
                throw new RuntimeException('Candidate mismatch.');
            }
            foreach (DB::table('jobs')->where('queue', FirstAccountActivation::QUEUE)->get(['payload']) as $row) {
                $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                if (($payload['displayName'] ?? null) !== DeliverOutboxNotification::class) {
                    throw new RuntimeException('Unknown queue job.');
                }
                $job = unserialize($payload['data']['command'], ['allowed_classes' => [DeliverOutboxNotification::class]]);
                $delivery = $job instanceof DeliverOutboxNotification ? NotificationDelivery::find($job->deliveryId) : null;
                if ($delivery === null || (int) $delivery->user_id !== $id || $delivery->type !== 'first_account_activation'
                    || ! app(LocalMailScope::class)->allows($delivery)) {
                    throw new RuntimeException('Queue recipient mismatch.');
                }
            }
            // Recover a commit-dispatch gap for this exact candidate only. Duplicate jobs are lease-fenced.
            $delivery = NotificationDelivery::query()->where('user_id', $id)->where('type', 'first_account_activation')
                ->whereIn('status', ['pending', 'failed'])->where('available_at', '<=', now('UTC'))->latest('id')->first();
            if ($delivery !== null && ! app(EnqueueNotificationDelivery::class)->handle((int) $delivery->id)) {
                throw new RuntimeException('Enqueue deferred.');
            }

            return $this->call('queue:work', [
                'connection' => 'database', '--queue' => FirstAccountActivation::QUEUE, '--stop-when-empty' => true,
                '--max-time' => 60, '--tries' => 1, '--timeout' => 60, '--sleep' => 1,
            ]);
        } catch (RuntimeException $problem) {
            $this->error('Pengiriman dibatalkan: environment, target, transport, atau isi antrean aktivasi tidak sesuai. Antrean lain tidak diproses.');

            return self::FAILURE;
        }
    }
}
