<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['path', 'uploaded_by'])]
class LandingPageMedia extends Model
{
    protected $table = 'landing_page_media';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';
}
