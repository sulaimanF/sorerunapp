<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['full_name', 'birth_date', 'phone', 'city', 'gender'])]
#[Hidden(['photo_path'])]
class MemberProfile extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['birth_date' => 'immutable_date:Y-m-d'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isComplete(): bool
    {
        return filled($this->full_name)
            && $this->birth_date !== null
            && ! $this->birth_date->isFuture()
            && filled($this->phone)
            && filled($this->city);
    }
}
