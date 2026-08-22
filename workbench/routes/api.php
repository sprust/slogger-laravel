<?php

use App\Events\NestedEvent;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
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

        // a route that binds a secret into its path: the value must never become a tag
        Route::get('/reset/{token}', fn(ResponseFactory $factory) => $factory->json(['ok' => true]))
            ->name('reset');

        // a SOAP-ish endpoint: an XML body in, an XML body out
        Route::post('/xml', function (Request $request, ResponseFactory $factory) {
            return $factory
                ->make(
                    '<response><api_token>sk-live-response</api_token><page>2</page></response>',
                    200,
                    ['Content-Type' => 'application/xml']
                );
        })->name('xml');

        Route::get('/failed', fn() => abort(500))
            ->name('failed');
        Route::get('/exception', fn() => throw new Exception('Test exception'))
            ->name('exception');
    }
);
