<?php

/**
 * October CMS - a Laravel-based platform engineered for simplicity.
 *
 * @package  october/october
 * @author   Alexey Bobkov, Samuel Georges
 */

/*
|--------------------------------------------------------------------------
| Check If The Application Is Under Maintenance
|--------------------------------------------------------------------------
|
| If the application is in maintenance / demo mode via the "down" command
| we will load this file so that any pre-rendered content can be shown
| instead of starting the framework, which could cause an exception.
|
*/

if (file_exists($maintenance = __DIR__ . '/storage/framework/maintenance.php')) {
    require $maintenance;
}

/*
|--------------------------------------------------------------------------
| Load deployment beacon
|--------------------------------------------------------------------------
|
| This is custom logic for the RainLab.Deploy plugin
|
*/

require __DIR__.'/bootstrap/beacon.php';

/*
|--------------------------------------------------------------------------
| Register composer
|--------------------------------------------------------------------------
|
| Composer provides a generated class loader for the application.
|
*/

require __DIR__ . '/bootstrap/autoload.php';

/*
|--------------------------------------------------------------------------
| Load framework
|--------------------------------------------------------------------------
|
| This bootstraps the framework and loads up this application.
|
*/

$app = require_once __DIR__ . '/bootstrap/app.php';

/*
|--------------------------------------------------------------------------
| Process request
|--------------------------------------------------------------------------
|
| Execute the request and send the response back to the client.
|
*/

$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);

$response = $kernel->handle(
    $request = \Illuminate\Http\Request::capture()
)->send();

$kernel->terminate($request, $response);
