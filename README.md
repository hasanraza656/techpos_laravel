# Tech POS backend and marketing

Laravel 10.50.4 / Sanctum 3.3.3 / PHP 8.2 target. Sole backend, shared tenant database, REST APIs and Blade/Bootstrap marketing website.

See the [root setup guide](../README.md), [continuity notes](../AI.md), [API contract](../docs/API.md) and [security status](../docs/SECURITY.md). This increment remains in development; Laravel 10 requires an upgrade before public deployment.

`php artisan test` runs isolated SQLite tests. `php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml` uses the explicitly named separate MySQL-compatible test database. Never run destructive test migrations against `tech_pos`.
