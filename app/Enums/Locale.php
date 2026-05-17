<?php

declare(strict_types=1);

namespace App\Enums;

enum Locale: string
{
    case Dutch = 'nl';
    case English = 'en';

    public function label(): string
    {
        $translation = __('common.locale.' . $this->value);

        return is_string($translation) ? $translation : $this->value;
    }
}
