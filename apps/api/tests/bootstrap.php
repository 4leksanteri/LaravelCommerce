<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test bootstrap
|--------------------------------------------------------------------------
|
| The suite runs against a database of its own, named from the development one
| rather than written out a second time. RefreshDatabase drops and recreates
| every table it finds, so a run pointed at DB_DATABASE would take the
| developer's own data with it.
|
| Deriving the name here rather than hard-coding it in phpunit.xml keeps one
| fact in one place: docker/postgres/init/01-create-test-database.sh creates
| "${POSTGRES_DB}_test" from the same variable, so renaming the database
| renames both.
|
*/

require __DIR__.'/../vendor/autoload.php';

$database = getenv('DB_DATABASE');

if ($database === false || $database === '') {
    $database = 'laravel_commerce';
}

if (! str_ends_with($database, '_test')) {
    $database .= '_test';
}

putenv("DB_DATABASE={$database}");
$_ENV['DB_DATABASE'] = $database;
$_SERVER['DB_DATABASE'] = $database;
