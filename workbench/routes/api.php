<?php

use App\Events\NestedEvent;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Support\Facades\Route;
use SLoggerLaravel\Middleware\HttpMiddleware;

Route::prefix('no-slogger')
    ->as('no-slogger.')
    ->get('/', fn(ResponseFactory $factory) => $factory->json(['ok' => true]))
    ->name('success');

Route::group(
    [
        'middleware' => [HttpMiddleware::class],
        'prefix'     => 'slogger',
        'as'         => 'slogger.',
    ],
    function () {
        Route::get('/success', function (ResponseFactory $factory) {
            event(new NestedEvent());

            return $factory->json(['ok' => true]);
        })->name('success');

        Route::get('/sensitive', function (ResponseFactory $factory) {
            return $factory
                ->json([
                    'ok'            => true,
                    'api_token'     => 'response-token',
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
