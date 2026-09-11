<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Covers foreign-key columns and filter/sort columns that PostgreSQL does
     * not index automatically. These back the hot read paths (agenda, fila,
     * caixa, repasses, prontuário, laudos, MT) and relation lookups.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->index('paid_at');
        });

        Schema::table('queue_entries', function (Blueprint $table): void {
            $table->index('service_date');
            $table->index('called_by');
        });

        Schema::table('cash_registers', function (Blueprint $table): void {
            $table->index('opened_by');
            $table->index('closed_by');
            $table->index('shortage_approved_by');
        });

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->index('payment_method_id');
            $table->index('created_by');
            $table->index(['cash_register_id', 'type']);
        });

        Schema::table('payouts', function (Blueprint $table): void {
            $table->index('payment_method_id');
            $table->index('cash_movement_id');
            $table->index('confirmed_by');
            $table->index('paid_by');
        });

        Schema::table('payout_items', function (Blueprint $table): void {
            $table->index('payout_id');
        });

        Schema::table('schedule_conflicts', function (Blueprint $table): void {
            $table->index('schedule_window_id');
            $table->index('schedule_exception_id');
            $table->index('created_by');
            $table->index('resolved_by');
        });

        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->index('unit_id');
            $table->index('created_by');
        });

        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->index('appointment_id');
            $table->index('user_id');
        });

        Schema::table('clinical_notes', function (Blueprint $table): void {
            $table->index('professional_id');
            $table->index('created_by');
            $table->index('updated_by');
        });

        Schema::table('patient_alerts', function (Blueprint $table): void {
            $table->index('created_by');
            $table->index('deactivated_by');
        });

        Schema::table('prontuario_events', function (Blueprint $table): void {
            $table->index('appointment_id');
            $table->index('subject_id');
            $table->index('by_user_id');
        });

        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->index('appointment_id');
            $table->index('user_id');
        });

        Schema::table('reports', function (Blueprint $table): void {
            $table->index('retention_until');
            $table->index('purged_at');
            $table->index('portal_access_id');
            $table->index('portal_published_by');
            $table->index('doctor_id');
            $table->index('typed_by');
        });

        Schema::table('report_events', function (Blueprint $table): void {
            $table->index('by_user_id');
        });

        Schema::table('budgets', function (Blueprint $table): void {
            $table->index('created_by');
            $table->index('resolved_by');
            $table->index('unit_id');
        });

        Schema::table('budget_items', function (Blueprint $table): void {
            $table->index('procedure_id');
            $table->index('procedure_price_id');
        });

        Schema::table('routine_tasks', function (Blueprint $table): void {
            $table->index('done_by');
        });

        Schema::table('loyalty_subscriptions', function (Blueprint $table): void {
            $table->index('created_by');
        });

        Schema::table('loyalty_dues', function (Blueprint $table): void {
            $table->index('cash_movement_id');
            $table->index('collected_by');
        });

        Schema::table('mt_functions', function (Blueprint $table): void {
            $table->index('sector_id');
            $table->index('risk_id');
        });

        Schema::table('mt_asos', function (Blueprint $table): void {
            $table->index('sector_id');
            $table->index('function_id');
            $table->index('appointment_id');
            $table->index('report_id');
            $table->index('created_by');
        });

        Schema::table('mt_aso_risks', function (Blueprint $table): void {
            $table->index('risk_id');
        });

        Schema::table('mt_aso_events', function (Blueprint $table): void {
            $table->index('by_user_id');
        });

        Schema::table('b2b_invoices', function (Blueprint $table): void {
            $table->index('created_by');
        });

        Schema::table('appointment_confirmations', function (Blueprint $table): void {
            $table->index('outbox_id');
        });

        Schema::table('agent_tokens', function (Blueprint $table): void {
            $table->index('created_by');
        });

        Schema::table('portal_accesses', function (Blueprint $table): void {
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('portal_accesses', fn (Blueprint $table) => $table->dropIndex(['created_by']));
        Schema::table('agent_tokens', fn (Blueprint $table) => $table->dropIndex(['created_by']));
        Schema::table('appointment_confirmations', fn (Blueprint $table) => $table->dropIndex(['outbox_id']));
        Schema::table('b2b_invoices', fn (Blueprint $table) => $table->dropIndex(['created_by']));
        Schema::table('mt_aso_events', fn (Blueprint $table) => $table->dropIndex(['by_user_id']));
        Schema::table('mt_aso_risks', fn (Blueprint $table) => $table->dropIndex(['risk_id']));
        Schema::table('mt_asos', function (Blueprint $table): void {
            $table->dropIndex(['sector_id']);
            $table->dropIndex(['function_id']);
            $table->dropIndex(['appointment_id']);
            $table->dropIndex(['report_id']);
            $table->dropIndex(['created_by']);
        });
        Schema::table('mt_functions', function (Blueprint $table): void {
            $table->dropIndex(['sector_id']);
            $table->dropIndex(['risk_id']);
        });
        Schema::table('loyalty_dues', function (Blueprint $table): void {
            $table->dropIndex(['cash_movement_id']);
            $table->dropIndex(['collected_by']);
        });
        Schema::table('loyalty_subscriptions', fn (Blueprint $table) => $table->dropIndex(['created_by']));
        Schema::table('routine_tasks', fn (Blueprint $table) => $table->dropIndex(['done_by']));
        Schema::table('budget_items', function (Blueprint $table): void {
            $table->dropIndex(['procedure_id']);
            $table->dropIndex(['procedure_price_id']);
        });
        Schema::table('budgets', function (Blueprint $table): void {
            $table->dropIndex(['created_by']);
            $table->dropIndex(['resolved_by']);
            $table->dropIndex(['unit_id']);
        });
        Schema::table('report_events', fn (Blueprint $table) => $table->dropIndex(['by_user_id']));
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropIndex(['retention_until']);
            $table->dropIndex(['purged_at']);
            $table->dropIndex(['portal_access_id']);
            $table->dropIndex(['portal_published_by']);
            $table->dropIndex(['doctor_id']);
            $table->dropIndex(['typed_by']);
        });
        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->dropIndex(['appointment_id']);
            $table->dropIndex(['user_id']);
        });
        Schema::table('prontuario_events', function (Blueprint $table): void {
            $table->dropIndex(['appointment_id']);
            $table->dropIndex(['subject_id']);
            $table->dropIndex(['by_user_id']);
        });
        Schema::table('patient_alerts', function (Blueprint $table): void {
            $table->dropIndex(['created_by']);
            $table->dropIndex(['deactivated_by']);
        });
        Schema::table('clinical_notes', function (Blueprint $table): void {
            $table->dropIndex(['professional_id']);
            $table->dropIndex(['created_by']);
            $table->dropIndex(['updated_by']);
        });
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropIndex(['appointment_id']);
            $table->dropIndex(['user_id']);
        });
        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->dropIndex(['unit_id']);
            $table->dropIndex(['created_by']);
        });
        Schema::table('schedule_conflicts', function (Blueprint $table): void {
            $table->dropIndex(['schedule_window_id']);
            $table->dropIndex(['schedule_exception_id']);
            $table->dropIndex(['created_by']);
            $table->dropIndex(['resolved_by']);
        });
        Schema::table('payout_items', fn (Blueprint $table) => $table->dropIndex(['payout_id']));
        Schema::table('payouts', function (Blueprint $table): void {
            $table->dropIndex(['payment_method_id']);
            $table->dropIndex(['cash_movement_id']);
            $table->dropIndex(['confirmed_by']);
            $table->dropIndex(['paid_by']);
        });
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropIndex(['payment_method_id']);
            $table->dropIndex(['created_by']);
            $table->dropIndex(['cash_register_id', 'type']);
        });
        Schema::table('cash_registers', function (Blueprint $table): void {
            $table->dropIndex(['opened_by']);
            $table->dropIndex(['closed_by']);
            $table->dropIndex(['shortage_approved_by']);
        });
        Schema::table('queue_entries', function (Blueprint $table): void {
            $table->dropIndex(['service_date']);
            $table->dropIndex(['called_by']);
        });
        Schema::table('appointments', fn (Blueprint $table) => $table->dropIndex(['paid_at']));
    }
};
