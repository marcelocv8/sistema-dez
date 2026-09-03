<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CatalogLinkController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ImportIssueController;
use App\Http\Controllers\Api\V1\OfferingPublicationController;
use App\Http\Controllers\Api\V1\PatientAppointmentController;
use App\Http\Controllers\Api\V1\PublicAppointmentController;
use App\Http\Controllers\Api\V1\PublicAvailabilityController;
use App\Http\Controllers\Api\V1\PublicServiceController;
use App\Http\Controllers\Api\V1\ScheduleExceptionController;
use App\Http\Controllers\Api\V1\ScheduleWindowController;
use App\Http\Controllers\Api\V1\ServiceOfferingController;
use App\Http\Controllers\Api\V1\TeamAppointmentController;
use App\Http\Controllers\Api\V1\TeamAvailabilityController;
use App\Http\Controllers\Api\V1\TeamPriceController;
use App\Http\Controllers\Api\V1\TeamPriceOptionsController;
use App\Http\Controllers\Api\V1\TeamUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/public')->middleware('throttle:public-api')->group(function (): void {
    Route::prefix('appointments/{id}')->middleware('throttle:patient-management')->group(function (): void {
        Route::get('', [PatientAppointmentController::class, 'show'])->name('api.v1.public.appointments.show');
        Route::get('availability', [PatientAppointmentController::class, 'availability'])->name('api.v1.public.appointments.availability');
        Route::patch('reschedule', [PatientAppointmentController::class, 'reschedule'])->name('api.v1.public.appointments.reschedule');
        Route::patch('cancel', [PatientAppointmentController::class, 'cancel'])->name('api.v1.public.appointments.cancel');
    });
    Route::get('services', PublicServiceController::class)->name('api.v1.public.services.index');
    Route::get('availability', PublicAvailabilityController::class)->name('api.v1.public.availability.index');
    Route::post('appointments', PublicAppointmentController::class)->name('api.v1.public.appointments.store');
});

Route::prefix('v1/auth')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:team-login')->name('api.v1.auth.login');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum')->name('api.v1.auth.logout');
});

Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:team-api'])->group(function (): void {
    Route::get('me', [AuthController::class, 'me'])->name('api.v1.me');
    Route::get('dashboard', DashboardController::class)->middleware('permission:schedule.view')->name('api.v1.dashboard');
    Route::prefix('team')->group(function (): void {
        Route::get('appointments', [TeamAppointmentController::class, 'index'])->middleware('permission:schedule.view')->name('api.v1.team.appointments.index');
        Route::get('availability', TeamAvailabilityController::class)->middleware('permission:schedule.view')->name('api.v1.team.availability');
        Route::middleware('permission:appointments.manage')->group(function (): void {
            Route::post('appointments', [TeamAppointmentController::class, 'store'])->name('api.v1.team.appointments.store');
            Route::post('patients/lookup', [TeamAppointmentController::class, 'lookupPatient'])->name('api.v1.team.patients.lookup');
            Route::patch('appointments/{appointment}/reschedule', [TeamAppointmentController::class, 'reschedule'])->name('api.v1.team.appointments.reschedule');
            Route::patch('appointments/{appointment}/cancel', [TeamAppointmentController::class, 'cancel'])->name('api.v1.team.appointments.cancel');
        });
        Route::get('service-offerings', [ServiceOfferingController::class, 'index'])->middleware('permission:schedule.view')->name('api.v1.team.service-offerings.index');
        Route::get('service-offering-options', [ServiceOfferingController::class, 'options'])->middleware('permission:schedule.view')->name('api.v1.team.service-offerings.options');
        Route::middleware('permission:schedule.manage')->group(function (): void {
            Route::put('service-offerings/{serviceOffering}/publication', [OfferingPublicationController::class, 'update'])->name('api.v1.team.publication.update');
            Route::delete('service-offerings/{serviceOffering}/publication', [OfferingPublicationController::class, 'destroy'])->name('api.v1.team.publication.destroy');
            Route::post('service-offerings', [ServiceOfferingController::class, 'store'])->name('api.v1.team.service-offerings.store');
            Route::put('service-offerings/{serviceOffering}', [ServiceOfferingController::class, 'update'])->name('api.v1.team.service-offerings.update');
            Route::delete('service-offerings/{serviceOffering}', [ServiceOfferingController::class, 'destroy'])->name('api.v1.team.service-offerings.destroy');
            Route::prefix('service-offerings/{serviceOffering}')->group(function (): void {
                Route::get('schedule-windows', [ScheduleWindowController::class, 'index'])->name('api.v1.team.schedule-windows.index');
                Route::post('schedule-windows', [ScheduleWindowController::class, 'store'])->name('api.v1.team.schedule-windows.store');
                Route::put('schedule-windows/{scheduleWindow}', [ScheduleWindowController::class, 'update'])->name('api.v1.team.schedule-windows.update');
                Route::delete('schedule-windows/{scheduleWindow}', [ScheduleWindowController::class, 'destroy'])->name('api.v1.team.schedule-windows.destroy');
                Route::get('schedule-exceptions', [ScheduleExceptionController::class, 'index'])->name('api.v1.team.schedule-exceptions.index');
                Route::post('schedule-exceptions', [ScheduleExceptionController::class, 'store'])->name('api.v1.team.schedule-exceptions.store');
                Route::put('schedule-exceptions/{scheduleException}', [ScheduleExceptionController::class, 'update'])->name('api.v1.team.schedule-exceptions.update');
                Route::delete('schedule-exceptions/{scheduleException}', [ScheduleExceptionController::class, 'destroy'])->name('api.v1.team.schedule-exceptions.destroy');
            });
        });
        Route::middleware('permission:prices.view')->group(function (): void {
            Route::get('price-options', TeamPriceOptionsController::class)->name('api.v1.team.price-options');
            Route::get('prices', TeamPriceController::class)->name('api.v1.team.prices');
        });

        Route::prefix('catalog')->whereIn('resource', ['units', 'agreements', 'procedures', 'professionals'])->group(function (): void {
            Route::get('{resource}', [CatalogController::class, 'index'])->middleware('permission:catalog.view')->name('api.v1.team.catalog.index');
            Route::get('{resource}/{id}', [CatalogController::class, 'show'])->middleware('permission:catalog.view')->name('api.v1.team.catalog.show');
            Route::post('{resource}', [CatalogController::class, 'store'])->middleware('permission:catalog.manage')->name('api.v1.team.catalog.store');
            Route::put('{resource}/{id}', [CatalogController::class, 'update'])->middleware('permission:catalog.manage')->name('api.v1.team.catalog.update');
            Route::delete('{resource}/{id}', [CatalogController::class, 'destroy'])->middleware('permission:catalog.manage')->name('api.v1.team.catalog.destroy');
        });

        Route::get('catalog-link-options', [CatalogLinkController::class, 'options'])->middleware('permission:catalog-links.view')->name('api.v1.team.catalog-links.options');
        Route::apiResource('catalog-links', CatalogLinkController::class)->except(['show'])->parameters(['catalog-links' => 'catalogLink'])->middlewareFor(['index'], 'permission:catalog-links.view')->middlewareFor(['store', 'update', 'destroy'], 'permission:catalog-links.manage');
        Route::apiResource('users', TeamUserController::class)->except(['show'])->parameters(['users' => 'teamUser'])->middleware('permission:team.manage');
        Route::get('import-issues', [ImportIssueController::class, 'index'])->middleware('permission:imports.view')->name('api.v1.team.import-issues.index');
        Route::put('import-issues/{importIssue}', [ImportIssueController::class, 'update'])->middleware('permission:imports.resolve')->name('api.v1.team.import-issues.update');
        Route::get('audit-logs', AuditLogController::class)->middleware('permission:audit.view')->name('api.v1.team.audit-logs.index');
    });
});
