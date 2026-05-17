<?php

declare(strict_types=1);

return [
    'llm_model' => env('RDWAI_LLM_MODEL', 'gpt-4.1-nano'),
    'rdw_app_token' => env('RDW_APP_TOKEN'),
    'rate_limit' => [
        'per_minute' => env('RDWAI_RATE_LIMIT_PER_MINUTE', 10),
        'per_day_global' => env('RDWAI_RATE_LIMIT_PER_DAY_GLOBAL', 1000),
    ],
];
