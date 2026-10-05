<?php

declare(strict_types=1);

// The API knows its users from a header (a token, in a real app): see AppServiceProvider.
return [
    'defaults' => ['guard' => 'api'],
    'guards' => [
        'api' => ['driver' => 'header'],
    ],
];
