<?php

use App\Http\Controllers\TripPlanController;
use Illuminate\Support\Facades\Route;

Route::get('/trip-plan', [TripPlanController::class, 'show']);
