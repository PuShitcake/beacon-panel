<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Controllers\Api\Beacon;

Route::get('/catalog/applications', [Beacon\CatalogController::class, 'index']);
Route::get('/catalog/applications/{application:slug}', [Beacon\CatalogController::class, 'show']);

Route::post('/servers', [Beacon\ServerController::class, 'store'])->name('api:beacon.servers.store');
Route::get('/servers/{server:uuid}', [Beacon\ServerController::class, 'show']);
Route::post('/servers/{server:uuid}/reinstall', [Beacon\ServerController::class, 'reinstall']);
Route::post('/servers/{server:uuid}/suspend', [Beacon\ServerController::class, 'suspend']);
Route::post('/servers/{server:uuid}/unsuspend', [Beacon\ServerController::class, 'unsuspend']);
Route::delete('/servers/{server:uuid}', [Beacon\ServerController::class, 'delete']);

Route::get('/operations/{operation:uuid}', [Beacon\OperationController::class, 'show']);
