<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationDeliveryStatus;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['dedupe_key', 'provider_reference', 'last_error_code'])]
class NotificationDelivery extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = [
        'status' => 'pending',
        'attempt_count' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => NotificationDeliveryStatus::class,
            'attempt_count' => 'integer',
            'available_at' => 'immutable_datetime',
            'lease_until' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
