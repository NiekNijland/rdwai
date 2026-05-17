<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\Locale;
use App\Models\User;

final readonly class PersistUserLocale
{
    public function handle(User $user, Locale $locale): void
    {
        $user->update(['locale' => $locale->value]);
    }
}
