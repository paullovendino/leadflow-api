<?php

use App\Http\Controllers\Api\V1\Appointments\AppointmentController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Availability\StaffAvailabilityController;
use App\Http\Controllers\Api\V1\Customers\CustomerActivityController;
use App\Http\Controllers\Api\V1\Customers\CustomerController;
use App\Http\Controllers\Api\V1\Customers\CustomerNoteController;
use App\Http\Controllers\Api\V1\Leads\LeadActivityController;
use App\Http\Controllers\Api\V1\Leads\LeadController;
use App\Http\Controllers\Api\V1\Leads\LeadNoteController;
use App\Http\Controllers\Api\V1\Pipeline\PipelineController;
use App\Http\Controllers\Api\V1\Services\ServiceController;
use App\Http\Controllers\Api\V1\Users\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:auth');

        Route::middleware(['auth:sanctum', 'active'])->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/user', [AuthController::class, 'user']);
        });
    });

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/activate', [UserController::class, 'activate']);
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);

        Route::get('/services', [ServiceController::class, 'index']);
        Route::post('/services', [ServiceController::class, 'store']);
        Route::get('/services/{service}', [ServiceController::class, 'show']);
        Route::patch('/services/{service}', [ServiceController::class, 'update']);
        Route::post('/services/{service}/activate', [ServiceController::class, 'activate']);
        Route::post('/services/{service}/deactivate', [ServiceController::class, 'deactivate']);

        Route::prefix('users/{user}')->scopeBindings()->group(function () {
            Route::get('/availabilities', [StaffAvailabilityController::class, 'index']);
            Route::post('/availabilities', [StaffAvailabilityController::class, 'store']);
            Route::get('/availabilities/{availability}', [StaffAvailabilityController::class, 'show']);
            Route::patch('/availabilities/{availability}', [StaffAvailabilityController::class, 'update']);
            Route::post('/availabilities/{availability}/activate', [StaffAvailabilityController::class, 'activate']);
            Route::post('/availabilities/{availability}/deactivate', [StaffAvailabilityController::class, 'deactivate']);
        });

        Route::get('/pipeline', [PipelineController::class, 'show']);
        Route::patch('/pipeline/stages/{stage}', [PipelineController::class, 'updateStage']);
        Route::post('/pipeline/stages/{stage}/activate', [PipelineController::class, 'activateStage']);
        Route::post('/pipeline/stages/{stage}/deactivate', [PipelineController::class, 'deactivateStage']);

        Route::get('/leads', [LeadController::class, 'index']);
        Route::post('/leads', [LeadController::class, 'store']);
        Route::get('/leads/{lead}', [LeadController::class, 'show']);
        Route::patch('/leads/{lead}', [LeadController::class, 'update']);
        Route::patch('/leads/{lead}/assignment', [LeadController::class, 'assignment']);
        Route::patch('/leads/{lead}/stage', [LeadController::class, 'stage']);
        Route::get('/leads/{lead}/notes', [LeadNoteController::class, 'index']);
        Route::post('/leads/{lead}/notes', [LeadNoteController::class, 'store']);
        Route::get('/leads/{lead}/activities', [LeadActivityController::class, 'index']);
        Route::post('/leads/{lead}/convert', [LeadController::class, 'convert']);

        Route::get('/customers', [CustomerController::class, 'index']);
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        Route::patch('/customers/{customer}', [CustomerController::class, 'update']);
        Route::get('/customers/{customer}/notes', [CustomerNoteController::class, 'index']);
        Route::post('/customers/{customer}/notes', [CustomerNoteController::class, 'store']);
        Route::get('/customers/{customer}/activities', [CustomerActivityController::class, 'index']);

        Route::get('/appointments/slots', [AppointmentController::class, 'slots']);
        Route::get('/appointments', [AppointmentController::class, 'index']);
        Route::post('/appointments', [AppointmentController::class, 'store']);
        Route::get('/appointments/{appointment}', [AppointmentController::class, 'show']);
        Route::patch('/appointments/{appointment}', [AppointmentController::class, 'update']);
        Route::patch('/appointments/{appointment}/status', [AppointmentController::class, 'status']);
    });
});
