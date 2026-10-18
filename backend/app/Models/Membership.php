<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Membership extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = ['status' => 'pending_verification'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'joined_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'anonymized_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(MemberCredential::class);
    }

    public function activeCredential(): HasOne
    {
        return $this->hasOne(MemberCredential::class)->whereNull('revoked_at');
    }
}
