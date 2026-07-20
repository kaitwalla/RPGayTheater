<?php

declare(strict_types=1);

return [
    'secret' => env('CONTROL_SECRET'),
    'user_email' => env('CONTROL_USER_EMAIL', 'control@rpgays.invalid'),
    'user_name' => env('CONTROL_USER_NAME', 'Control'),
    'secret_confirmation_seconds' => (int) env('CONTROL_SECRET_CONFIRMATION_SECONDS', 900),
];
