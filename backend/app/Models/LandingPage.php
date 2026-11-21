<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LandingPage extends Model
{
    public $incrementing = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['draft' => 'array', 'draft_version' => 'integer'];
    }
}
