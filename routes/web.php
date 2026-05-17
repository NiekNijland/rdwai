<?php

declare(strict_types=1);

use App\Enums\Locale;
use App\Http\Controllers\Auth\UpdateLocaleController;
use App\Http\Controllers\Rdw\QueryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', static function (Request $request) {
    $query = $request->getQueryString();

    return redirect('/' . app()->getLocale() . ($query !== null && $query !== '' ? '?' . $query : ''));
});

Route::prefix('{locale}')
    ->whereIn('locale', array_map(
        static fn (Locale $locale): string => $locale->value,
        Locale::cases(),
    ))
    ->group(function (): void {
        Route::get('/', [QueryController::class, 'index'])->name('home');
    });

Route::post('/api/query', [QueryController::class, 'run'])
    ->middleware('throttle:rdw-query')
    ->name('rdw.query.run');

Route::post('locale', UpdateLocaleController::class)->name('locale.update');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__ . '/settings.php';
