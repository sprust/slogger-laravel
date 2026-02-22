<?php

use App\Events\NestedEvent;
use Illuminate\Support\Facades\Route;
use SLoggerLaravel\Middleware\HttpMiddleware;

Route::prefix('no-slogger')
    ->as('no-slogger.')
    ->get('/', fn() => response()->json(['ok' => true]))
    ->name('success');

Route::group(
    [
        'middleware' => [HttpMiddleware::class],
        'prefix' => 'slogger',
        'as' => 'slogger.'
    ],
    function () {
        Route::get('/success', function () {
            event(new NestedEvent());
            return response()->json(['ok' => true]);
        })->name('success');

        Route::get('/sensitive', function () {
            return response()
                ->json([
                    'ok' => true,
                    'api_token' => 'response-token',
                    'user_password' => 'response-password',
                ])
                ->header('set-cookie', 'session=response-cookie');
        })->name('sensitive');

        Route::get('/failed', fn() => abort(500))
            ->name('failed');
        Route::get('/exception', fn() => throw new Exception('Test exception'))
            ->name('exception');
    }
);
