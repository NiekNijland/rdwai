<?php

declare(strict_types=1);

return [
    'llm_model' => env('RDWAI_LLM_MODEL', 'gpt-4.1-nano'),
    'rdw_app_token' => env('RDW_APP_TOKEN'),
    'rate_limit' => [
        'per_minute' => env('RDWAI_RATE_LIMIT_PER_MINUTE', 10),
        'per_day_global' => env('RDWAI_RATE_LIMIT_PER_DAY_GLOBAL', 50),
        'feedback_per_minute' => env('RDWAI_RATE_LIMIT_FEEDBACK_PER_MINUTE', 30),
        'read_per_minute' => env('RDWAI_RATE_LIMIT_READ_PER_MINUTE', 60),
    ],
    // Per-model USD prices per 1,000,000 tokens, used by CostEstimator to
    // attach an estimate to each persisted query run. Keys without a `-`
    // suffix are also matched as family prefixes for dated OpenAI variants
    // (e.g. `gpt-4.1-nano` covers `gpt-4.1-nano-2025-04-14`).
    'model_prices' => [
        'gpt-4.1-nano' => [
            'input' => 0.10,
            'cached_input' => 0.025,
            'output' => 0.40,
        ],
        'gpt-4.1-mini' => [
            'input' => 0.40,
            'cached_input' => 0.10,
            'output' => 1.60,
        ],
        'gpt-4.1' => [
            'input' => 2.00,
            'cached_input' => 0.50,
            'output' => 8.00,
        ],
    ],
];
