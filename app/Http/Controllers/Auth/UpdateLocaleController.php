<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\PersistUserLocale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateLocaleRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class UpdateLocaleController extends Controller
{
    public function __invoke(
        UpdateLocaleRequest $request,
        PersistUserLocale $persistUserLocale,
    ): RedirectResponse {
        $locale = $request->resolvedLocale();

        $request->session()->put('locale', $locale->value);
        app()->setLocale($locale->value);

        $user = $request->user();

        if ($user instanceof User) {
            $persistUserLocale->handle($user, $locale);
        }

        return redirect()->back();
    }
}
