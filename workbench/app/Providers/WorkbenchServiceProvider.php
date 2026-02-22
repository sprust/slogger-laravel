<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\SloggerTestExceptedCommand;
use App\Console\Commands\SloggerTestFailedCommand;
use App\Console\Commands\SloggerTestNestedEventCommand;
use App\Console\Commands\SloggerTestSuccessCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::group([], realpath(__DIR__ . '/../../routes/api.php'));

        $this->commands([
            SloggerTestSuccessCommand::class,
            SloggerTestFailedCommand::class,
            SloggerTestNestedEventCommand::class,
            SloggerTestExceptedCommand::class,
        ]);
    }
}