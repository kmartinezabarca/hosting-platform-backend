<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});


Route::post("auth/register", [App\Http\Controllers\AuthController::class, "register"]);
Route::post("auth/login", [App\Http\Controllers\AuthController::class, "login"]);
Route::post("auth/logout", [App\Http\Controllers\AuthController::class, "logout"])->middleware("auth:sanctum");



// Dashboard routes (protected)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard/stats', [App\Http\Controllers\DashboardController::class, 'getStats']);
    Route::get('/dashboard/services', [App\Http\Controllers\DashboardController::class, 'getServices']);
    Route::get('/dashboard/activity', [App\Http\Controllers\DashboardController::class, 'getActivity']);
});


// Temporary routes for testing (without authentication)
Route::get('/test/dashboard/stats', [App\Http\Controllers\DashboardController::class, 'getStats']);
Route::get('/test/dashboard/services', [App\Http\Controllers\DashboardController::class, 'getServices']);
Route::get('/test/dashboard/activity', [App\Http\Controllers\DashboardController::class, 'getActivity']);

