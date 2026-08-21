<?php

use App\Http\Controllers\FareReportController;
use App\Http\Controllers\TripPlanController;
use Illuminate\Support\Facades\Route;

Route::get('/trip-plan', [TripPlanController::class, 'show']);
Route::post('/fare-reports', [FareReportController::class, 'store'])->middleware('throttle:10,1');
