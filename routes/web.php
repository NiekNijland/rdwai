<?php

use App\Http\Controllers\Rdw\QueryController;
use Illuminate\Support\Facades\Route;

Route::get('/', [QueryController::class, 'index'])->name('home');
Route::post('/api/query', [QueryController::class, 'run'])
    ->middleware('throttle:10,1')
    ->name('rdw.query.run');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
