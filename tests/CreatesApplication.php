<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();
        $connection = config('database.default');
        $database = (string) config('database.connections.'.$connection.'.database');
        if ($connection !== 'sqlite' && ! str_starts_with($database, 'tech_pos_codex_test_')) {
            throw new \RuntimeException('Refusing destructive test migrations outside the isolated tech_pos_codex_test_* database namespace.');
        }

        return $app;
    }
}
