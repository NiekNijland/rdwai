<?php

declare(strict_types=1);

return [
    'llm_model' => env('RDWAI_LLM_MODEL', 'gpt-4.1-nano'),
    'rdw_app_token' => env('RDW_APP_TOKEN'),
];
