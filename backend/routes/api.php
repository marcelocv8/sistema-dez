<?php

use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AppointmentPaymentController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\B2bInvoiceController;
use App\Http\Controllers\Api\V1\BudgetController;
use App\Http\Controllers\Api\V1\CashRegisterController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CatalogLinkController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DisplayController;
use App\Http\Controllers\Api\V1\HermesAgentController;
use App\Http\Controllers\Api\V1\HermesController;
use App\Http\Controllers\Api\V1\ImportIssueController;
use App\Http\Controllers\Api\V1\LoyaltyController;
use App\Http\Controllers\Api\V1\MtController;
use App\Http\Controllers\Api\V1\OfferingVisibilityController;
use App\Http\Controllers\Api\V1\PatientAppointmentController;
use App\Http\Controllers\Api\V1\PayoutController;
use App\Http\Controllers\Api\V1\PortalController;
use App\Http\Controllers\Api\V1\ProfessionalCommissionController;
use App\Http\Controllers\Api\V1\ProntuarioController;
use App\Http\Controllers\Api\V1\PublicAppointmentController;
use App\Http\Controllers\Api\V1\PublicAvailabilityController;
use App\Http\Controllers\Api\V1\PublicServiceController;
use App\Http\Controllers\Api\V1\QueueController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RoutineController;
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
    Route::prefix('portal')->middleware('throttle:portal-auth')->group(function (): void {
        Route::post('login', [PortalController::class, 'login'])->name('api.v1.portal.login');
        Route::get('results', [PortalController::class, 'results'])->name('api.v1.portal.results');
    });
    Route::get('units', [DisplayController::class, 'units'])->name('api.v1.public.units.index');
    Route::get('display', [DisplayController::class, 'show'])->name('api.v1.public.display.show');
});

Route::prefix('v1/agents')->middleware(['throttle:agents', 'agent.token'])->group(function (): void {
    Route::get('agenda', [HermesController::class, 'agenda'])->middleware('agent.scope:agenda.read')->name('api.v1.agents.agenda');
    Route::get('patients', [HermesController::class, 'patients'])->middleware('agent.scope:patients.read')->name('api.v1.agents.patients');
    Route::get('production', [HermesController::class, 'production'])->middleware('agent.scope:finance.read')->name('api.v1.agents.production');
    Route::get('cash-summary', [HermesController::class, 'cashSummary'])->middleware('agent.scope:finance.read')->name('api.v1.agents.cash-summary');
    Route::get('b2b-invoices', [HermesController::class, 'b2bInvoices'])->middleware('agent.scope:finance.read')->name('api.v1.agents.b2b-invoices');
    Route::get('asos/due', [HermesController::class, 'asosDue'])->middleware('agent.scope:mt.read')->name('api.v1.agents.asos.due');
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
            Route::post('appointments/{appointment}/check-in', [AppointmentPaymentController::class, 'checkIn'])->name('api.v1.team.appointments.check-in');
            Route::post('appointments/{appointment}/start-care', [AppointmentPaymentController::class, 'startCare'])->name('api.v1.team.appointments.start-care');
            Route::post('appointments/{appointment}/authorize-b2b', [AppointmentPaymentController::class, 'authorizeB2B'])->name('api.v1.team.appointments.authorize-b2b');
        });
        Route::middleware('permission:cash.operate')->group(function (): void {
            Route::post('appointments/{appointment}/checkout', [AppointmentPaymentController::class, 'checkout'])->name('api.v1.team.appointments.checkout');
            Route::post('cash-registers', [CashRegisterController::class, 'store'])->name('api.v1.team.cash-registers.store');
        });
        Route::middleware('permission:cash.view')->group(function (): void {
            Route::get('cash-registers', [CashRegisterController::class, 'index'])->name('api.v1.team.cash-registers.index');
            Route::get('cash-registers/current', [CashRegisterController::class, 'current'])->name('api.v1.team.cash-registers.current');
            Route::get('cash-registers/daily-report', [CashRegisterController::class, 'dailyReport'])->name('api.v1.team.cash-registers.daily-report');
        });
        Route::post('cash-registers/close-batch', [CashRegisterController::class, 'closeBatch'])->middleware('permission:cash.close')->name('api.v1.team.cash-registers.close-batch');
        Route::post('cash-registers/{cashRegister}/close', [CashRegisterController::class, 'close'])->middleware('permission:cash.close')->name('api.v1.team.cash-registers.close');
        Route::post('cash-registers/{cashRegister}/sangrias', [CashRegisterController::class, 'sangria'])->middleware('permission:cash.operate')->name('api.v1.team.cash-registers.sangria');
        Route::post('appointments/{appointment}/void-payment', [AppointmentPaymentController::class, 'voidPayment'])->middleware('permission:discounts.validate')->name('api.v1.team.appointments.void');
        Route::get('b2b-invoices', [B2bInvoiceController::class, 'index'])->middleware('permission:b2b.view')->name('api.v1.team.b2b-invoices.index');
        Route::get('b2b-invoices/{b2bInvoice}', [B2bInvoiceController::class, 'show'])->middleware('permission:b2b.view')->name('api.v1.team.b2b-invoices.show');
        Route::post('b2b-invoices/generate', [B2bInvoiceController::class, 'generate'])->middleware('permission:b2b.manage')->name('api.v1.team.b2b-invoices.generate');
        Route::post('b2b-invoices/{b2bInvoice}/send', [B2bInvoiceController::class, 'send'])->middleware('permission:b2b.manage')->name('api.v1.team.b2b-invoices.send');
        Route::post('b2b-invoices/{b2bInvoice}/settle', [B2bInvoiceController::class, 'settle'])->middleware('permission:b2b.settle')->name('api.v1.team.b2b-invoices.settle');
        Route::get('queue', [QueueController::class, 'index'])->middleware('permission:schedule.view')->name('api.v1.team.queue.index');
        Route::get('queue/history', [QueueController::class, 'history'])->middleware('permission:schedule.view')->name('api.v1.team.queue.history');
        Route::post('queue/{queueEntry}/reorder', [QueueController::class, 'reorder'])->middleware('permission:appointments.manage')->name('api.v1.team.queue.reorder');
        Route::post('queue/{queueEntry}/call', [QueueController::class, 'call'])->middleware('permission:queue.call')->name('api.v1.team.queue.call');
        Route::post('schedule-conflicts/{scheduleConflict}/resolve', [QueueController::class, 'resolveConflict'])->middleware('permission:schedule.manage')->name('api.v1.team.schedule-conflicts.resolve');
        Route::get('schedule-conflicts', [QueueController::class, 'conflicts'])->middleware('permission:schedule.view')->name('api.v1.team.schedule-conflicts.index');
        Route::get('attendance', [AttendanceController::class, 'index'])->middleware('permission:attendance.view')->name('api.v1.team.attendance.index');
        Route::get('attendance/export', [AttendanceController::class, 'export'])->middleware('permission:attendance.view')->name('api.v1.team.attendance.export');
        Route::get('attendance/exit-hour', [AttendanceController::class, 'showExitHour'])->middleware('permission:attendance.view')->name('api.v1.team.attendance.exit-hour.show');
        Route::put('attendance/exit-hour', [AttendanceController::class, 'updateExitHour'])->middleware('permission:team.manage')->name('api.v1.team.attendance.exit-hour.update');
        Route::prefix('chat')->middleware('permission:chat.use')->group(function (): void {
            Route::get('conversations', [ChatController::class, 'conversations'])->name('api.v1.team.chat.conversations');
            Route::post('conversations', [ChatController::class, 'store'])->name('api.v1.team.chat.conversations.store');
            Route::get('participants', [ChatController::class, 'participants'])->name('api.v1.team.chat.participants');
            Route::get('unread', [ChatController::class, 'unread'])->name('api.v1.team.chat.unread');
            Route::get('patients/lookup', [ChatController::class, 'lookupPatient'])->name('api.v1.team.chat.patients.lookup');
            Route::get('conversations/{chatConversation}/messages', [ChatController::class, 'messages'])->name('api.v1.team.chat.messages');
            Route::post('conversations/{chatConversation}/messages', [ChatController::class, 'sendMessage'])->name('api.v1.team.chat.messages.store');
            Route::post('conversations/{chatConversation}/read', [ChatController::class, 'markRead'])->name('api.v1.team.chat.read');
        });
        Route::get('budgets', [BudgetController::class, 'index'])->middleware('permission:budgets.view')->name('api.v1.team.budgets.index');
        Route::get('budgets/price-options', [BudgetController::class, 'priceOptions'])->middleware('permission:budgets.view')->name('api.v1.team.budgets.price-options');
        Route::get('budgets/{budget}/prescription', [BudgetController::class, 'prescription'])->middleware('permission:budgets.view')->name('api.v1.team.budgets.prescription');
        Route::post('budgets', [BudgetController::class, 'store'])->middleware('permission:budgets.manage')->name('api.v1.team.budgets.store');
        Route::post('budgets/{budget}/send', [BudgetController::class, 'send'])->middleware('permission:budgets.manage')->name('api.v1.team.budgets.send');
        Route::post('budgets/{budget}/resolve', [BudgetController::class, 'resolve'])->middleware('permission:budgets.manage')->name('api.v1.team.budgets.resolve');
        Route::get('routines', [RoutineController::class, 'index'])->middleware('permission:routine.view')->name('api.v1.team.routines.index');
        Route::post('routines/generate', [RoutineController::class, 'generate'])->middleware('permission:routine.manage')->name('api.v1.team.routines.generate');
        Route::post('routines/{routineTask}/tick', [RoutineController::class, 'tick'])->middleware('permission:routine.tick')->name('api.v1.team.routines.tick');
        Route::post('routines/{routineTask}/reopen', [RoutineController::class, 'reopen'])->middleware('permission:routine.manage')->name('api.v1.team.routines.reopen');
        Route::get('reports/templates', [ReportController::class, 'templates'])->middleware('permission:reports.view')->name('api.v1.team.reports.templates');
        Route::get('reports/history', [ReportController::class, 'history'])->middleware('permission:reports.view')->name('api.v1.team.reports.history');
        Route::get('reports', [ReportController::class, 'index'])->middleware('permission:reports.view')->name('api.v1.team.reports.index');
        Route::get('reports/{report}', [ReportController::class, 'show'])->middleware('permission:reports.view')->name('api.v1.team.reports.show');
        Route::post('reports', [ReportController::class, 'store'])->middleware('permission:reports.write')->name('api.v1.team.reports.store');
        Route::put('reports/{report}', [ReportController::class, 'update'])->middleware('permission:reports.write')->name('api.v1.team.reports.update');
        Route::post('reports/{report}/finalize', [ReportController::class, 'finalize'])->middleware('permission:reports.write')->name('api.v1.team.reports.finalize');
        Route::post('reports/{report}/append', [ReportController::class, 'append'])->middleware('permission:reports.write')->name('api.v1.team.reports.append');
        Route::post('reports/{report}/sign-manual', [ReportController::class, 'signManual'])->middleware('permission:reports.sign')->name('api.v1.team.reports.sign-manual');
        Route::post('reports/{report}/print', [ReportController::class, 'print'])->middleware('permission:reports.view')->name('api.v1.team.reports.print');
        Route::post('reports/{report}/portal', [PortalController::class, 'publish'])->middleware('permission:reports.write')->name('api.v1.team.reports.portal.publish');
        Route::get('reports/{report}/portal/folha', [PortalController::class, 'folha'])->middleware('permission:reports.view')->name('api.v1.team.reports.portal.folha');
        Route::post('reports/{report}/portal/revoke', [PortalController::class, 'revokePublication'])->middleware('permission:reports.write')->name('api.v1.team.reports.portal.revoke');
        Route::get('prontuario', [ProntuarioController::class, 'timeline'])->middleware('permission:prontuario.view')->name('api.v1.team.prontuario.timeline');
        Route::post('prontuario/notes', [ProntuarioController::class, 'storeNote'])->middleware('permission:prontuario.write')->name('api.v1.team.prontuario.notes.store');
        Route::put('prontuario/notes/{clinicalNote}', [ProntuarioController::class, 'updateNote'])->middleware('permission:prontuario.write')->name('api.v1.team.prontuario.notes.update');
        Route::post('prontuario/alerts', [ProntuarioController::class, 'addAlert'])->middleware('permission:prontuario.write')->name('api.v1.team.prontuario.alerts.store');
        Route::post('prontuario/alerts/{patientAlert}/deactivate', [ProntuarioController::class, 'deactivateAlert'])->middleware('permission:prontuario.write')->name('api.v1.team.prontuario.alerts.deactivate');
        Route::get('prontuario/ai/status', [ProntuarioController::class, 'aiStatus'])->middleware('permission:prontuario.view')->name('api.v1.team.prontuario.ai.status');
        Route::post('prontuario/ai/suggest', [ProntuarioController::class, 'aiSuggest'])->middleware('permission:ai.use')->name('api.v1.team.prontuario.ai.suggest');
        Route::middleware('permission:payouts.view')->group(function (): void {
            Route::get('payouts', [PayoutController::class, 'index'])->name('api.v1.team.payouts.index');
            Route::get('payouts/{payout}', [PayoutController::class, 'show'])->name('api.v1.team.payouts.show');
        });
        Route::post('payouts/generate', [PayoutController::class, 'generate'])->name('api.v1.team.payouts.generate');
        Route::patch('payout-items/{payoutItem}/commission', [PayoutController::class, 'updateItem'])->middleware('permission:payouts.manage')->name('api.v1.team.payout-items.commission');
        Route::post('payouts/{payout}/confirm', [PayoutController::class, 'confirm'])->middleware('permission:payouts.confirm')->name('api.v1.team.payouts.confirm');
        Route::post('payouts/{payout}/contest', [PayoutController::class, 'contest'])->middleware('permission:payouts.confirm')->name('api.v1.team.payouts.contest');
        Route::post('payouts/{payout}/pay', [PayoutController::class, 'pay'])->middleware('permission:payouts.pay')->name('api.v1.team.payouts.pay');
        Route::get('professionals/mine', [ProfessionalCommissionController::class, 'mine'])->middleware('permission:payouts.view')->name('api.v1.team.professionals.mine');
        Route::get('professionals/{professional}/commissions', [ProfessionalCommissionController::class, 'index'])->middleware('permission:catalog.view')->name('api.v1.team.professional-commissions.index');
        Route::post('professionals/{professional}/commissions', [ProfessionalCommissionController::class, 'store'])->middleware('permission:catalog.manage')->name('api.v1.team.professional-commissions.store');
        Route::delete('professionals/{professional}/commissions/{commission}', [ProfessionalCommissionController::class, 'destroy'])->middleware('permission:catalog.manage')->name('api.v1.team.professional-commissions.destroy');
        Route::get('service-offerings', [ServiceOfferingController::class, 'index'])->middleware('permission:schedule.view')->name('api.v1.team.service-offerings.index');
        Route::get('service-offering-options', [ServiceOfferingController::class, 'options'])->middleware('permission:schedule.view')->name('api.v1.team.service-offerings.options');
        Route::middleware('permission:schedule.manage')->group(function (): void {
            Route::patch('service-offerings/{serviceOffering}/visibility', [OfferingVisibilityController::class, 'update'])->name('api.v1.team.visibility.update');
            Route::post('service-offerings', [ServiceOfferingController::class, 'store'])->name('api.v1.team.service-offerings.store');
            Route::put('service-offerings/{serviceOffering}', [ServiceOfferingController::class, 'update'])->name('api.v1.team.service-offerings.update');
            Route::delete('service-offerings/{serviceOffering}', [ServiceOfferingController::class, 'destroy'])->name('api.v1.team.service-offerings.destroy');
            Route::prefix('service-offerings/{serviceOffering}')->group(function (): void {
                Route::get('schedule-windows', [ScheduleWindowController::class, 'index'])->name('api.v1.team.schedule-windows.index');
                Route::post('schedule-windows', [ScheduleWindowController::class, 'store'])->name('api.v1.team.schedule-windows.store');
                Route::post('schedule-windows/import', [ScheduleWindowController::class, 'import'])->name('api.v1.team.schedule-windows.import');
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
        Route::prefix('loyalty')->middleware('permission:soudez.view')->group(function (): void {
            Route::get('plans', [LoyaltyController::class, 'plans'])->name('api.v1.team.loyalty.plans.index');
            Route::post('plans', [LoyaltyController::class, 'storePlan'])->middleware('permission:soudez.manage')->name('api.v1.team.loyalty.plans.store');
            Route::patch('plans/{loyaltyPlan}', [LoyaltyController::class, 'updatePlan'])->middleware('permission:soudez.manage')->name('api.v1.team.loyalty.plans.update');
            Route::get('subscriptions', [LoyaltyController::class, 'subscriptions'])->name('api.v1.team.loyalty.subscriptions.index');
            Route::post('subscriptions', [LoyaltyController::class, 'storeSubscription'])->middleware('permission:soudez.manage')->name('api.v1.team.loyalty.subscriptions.store');
            Route::get('subscriptions/{loyaltySubscription}', [LoyaltyController::class, 'showSubscription'])->name('api.v1.team.loyalty.subscriptions.show');
            Route::patch('subscriptions/{loyaltySubscription}', [LoyaltyController::class, 'updateSubscription'])->middleware('permission:soudez.manage')->name('api.v1.team.loyalty.subscriptions.update');
            Route::get('dues', [LoyaltyController::class, 'dues'])->name('api.v1.team.loyalty.dues.index');
            Route::post('dues/{loyaltyDue}/collect', [LoyaltyController::class, 'collect'])->name('api.v1.team.loyalty.dues.collect');
            Route::get('eligibility', [LoyaltyController::class, 'eligibility'])->name('api.v1.team.loyalty.eligibility');
        });
        Route::get('analytics', [AnalyticsController::class, 'index'])->middleware('permission:analytics.view')->name('api.v1.team.analytics.index');
        Route::get('analytics/export', [AnalyticsController::class, 'export'])->middleware('permission:analytics.view')->name('api.v1.team.analytics.export');
        Route::get('agents', [HermesAgentController::class, 'index'])->middleware('permission:team.manage')->name('api.v1.team.agents.index');
        Route::post('agents', [HermesAgentController::class, 'store'])->middleware('permission:team.manage')->name('api.v1.team.agents.store');
        Route::post('agents/{agentToken}/revoke', [HermesAgentController::class, 'revoke'])->middleware('permission:team.manage')->name('api.v1.team.agents.revoke');
        Route::prefix('mt')->middleware('permission:mt.view')->group(function (): void {
            Route::get('options', [MtController::class, 'options'])->name('api.v1.team.mt.options');
            Route::get('risks', [MtController::class, 'risksIndex'])->name('api.v1.team.mt.risks.index');
            Route::post('risks', [MtController::class, 'risksStore'])->middleware('permission:mt.manage')->name('api.v1.team.mt.risks.store');
            Route::put('risks/{mtRisk}', [MtController::class, 'risksUpdate'])->middleware('permission:mt.manage')->name('api.v1.team.mt.risks.update');
            Route::get('sectors', [MtController::class, 'sectorsIndex'])->name('api.v1.team.mt.sectors.index');
            Route::post('sectors', [MtController::class, 'sectorsStore'])->middleware('permission:mt.manage')->name('api.v1.team.mt.sectors.store');
            Route::put('sectors/{mtSector}', [MtController::class, 'sectorsUpdate'])->middleware('permission:mt.manage')->name('api.v1.team.mt.sectors.update');
            Route::get('functions', [MtController::class, 'functionsIndex'])->name('api.v1.team.mt.functions.index');
            Route::post('functions', [MtController::class, 'functionsStore'])->middleware('permission:mt.manage')->name('api.v1.team.mt.functions.store');
            Route::put('functions/{mtFunction}', [MtController::class, 'functionsUpdate'])->middleware('permission:mt.manage')->name('api.v1.team.mt.functions.update');
            Route::get('asos/due', [MtController::class, 'asosDue'])->name('api.v1.team.mt.asos.due');
            Route::get('asos', [MtController::class, 'asosIndex'])->name('api.v1.team.mt.asos.index');
            Route::post('asos', [MtController::class, 'asosStore'])->middleware('permission:mt.manage')->name('api.v1.team.mt.asos.store');
            Route::put('asos/{mtAso}', [MtController::class, 'asosUpdate'])->middleware('permission:mt.manage')->name('api.v1.team.mt.asos.update');
            Route::post('asos/{mtAso}/cancel', [MtController::class, 'asosCancel'])->middleware('permission:mt.manage')->name('api.v1.team.mt.asos.cancel');
            Route::post('asos/{mtAso}/extend', [MtController::class, 'asosExtend'])->middleware('permission:mt.manage')->name('api.v1.team.mt.asos.extend');
            Route::post('asos/{mtAso}/link-exam', [MtController::class, 'asosLinkExam'])->middleware('permission:mt.manage')->name('api.v1.team.mt.asos.link-exam');
            Route::post('asos/{mtAso}/emit', [MtController::class, 'asosEmit'])->middleware('permission:mt.manage')->name('api.v1.team.mt.asos.emit');
            Route::post('batch-schedule', [MtController::class, 'batchSchedule'])->middleware('permission:mt.manage')->name('api.v1.team.mt.batch-schedule');
            Route::get('companies/{agreement}/report', [MtController::class, 'companyReport'])->name('api.v1.team.mt.companies.report');
        });
    });
});
