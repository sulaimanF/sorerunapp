<?php

declare(strict_types=1);

return [
    'registration' => [
        'privacy_notice_version' => env('REGISTRATION_PRIVACY_VERSION'),
        'terms_version' => env('REGISTRATION_TERMS_VERSION'),
    ],
    'community_name' => 'Sore Running Club',
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    'membership' => ['number_prefix' => env('MEMBER_NUMBER_PREFIX')],
    'outbox' => [
        // Tests explicitly enable enqueue when checking commit behavior.
        'dispatch_on_commit' => env('OUTBOX_DISPATCH_ON_COMMIT', env('APP_ENV') !== 'testing'),
    ],
];
