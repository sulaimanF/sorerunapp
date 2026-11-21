<?php

declare(strict_types=1);

namespace App\Http\Resources\Content;

use App\Exceptions\ApiProblem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LandingMediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $origin = rtrim((string) config('app.url'), '/');
        $parts = parse_url($origin);
        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || ! empty($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ApiProblem('CONFIGURATION_UNAVAILABLE', 'Konfigurasi media belum tersedia.', 503);
        }
        $scope = $request->is('api/v1/admin/*') ? 'admin' : 'public';

        return [
            'id' => $this->public_id, 'mime' => $this->mime,
            'width' => (int) $this->width, 'height' => (int) $this->height,
            'url' => $origin.'/api/v1/'.$scope.'/landing-page/media/'.$this->public_id,
        ];
    }
}
