<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class LandingPageRevision extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['content' => 'array', 'draft_version' => 'integer', 'published_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        // No API mutates a published snapshot. Guard accidental model writes as well.
        static::updating(fn () => throw new LogicException('Published content is immutable.'));
        static::deleting(fn () => throw new LogicException('Published content is retained.'));
    }
}
