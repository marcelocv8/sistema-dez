<?php

namespace Tests\Feature;

use App\Actions\Scheduling\MaterializeScheduleOccurrences;
use App\Enums\UserRole;
use App\Models\CatalogLink;
use App\Models\ProcedurePrice;
use App\Models\Professional;
use App\Models\QueueEntry;
use App\Models\ServiceOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class QueueTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_queue_lists_suggested_order_without_erasing_arrival(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offering();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $first = $this->book($offering, '2026-09-04T08:00:00-03:00', '11144477735');
        $second = $this->book($offering, '2026-09-04T08:30:00-03:00', '12345678909');
        $this->postJson("/api/v1/team/appointments/{$first}/check-in")->assertOk();
        $this->postJson("/api/v1/team/appointments/{$second}/check-in")->assertOk();

        // Segunda chegada recebe prioridade legal: sobe na sugestão, sem apagar a chegada original.
        $entries = QueueEntry::query()->orderBy('original_sequence')->get();
        $this->postJson("/api/v1/team/queue/{$entries[1]->id}/reorder", [
            'priority_code' => 'GESTANTE', 'priority_reason' => 'Gestante com prioridade legal',
        ])->assertOk()->assertJsonPath('data.priority_rank', 2);

        $queue = $this->getJson('/api/v1/team/queue?date=2026-09-04')->assertOk()->json('data.queue');
        $this->assertCount(2, $queue);
        $this->assertSame($entries[1]->id, $queue[0]['id']);
        $this->assertSame(1, $queue[0]['suggested_position']);
        $this->assertSame('A002', $queue[0]['ticket']);
        $this->assertSame(2, $queue[0]['original_sequence']);
        $this->assertSame('GESTANTE', $queue[0]['priority_code']);
        $this->assertSame($entries[0]->id, $queue[1]['id']);
        $this->assertSame(2, $queue[1]['suggested_position']);
        $this->assertDatabaseHas('audit_logs', ['entity_key' => $entries[1]->id, 'action' => 'queue.reordered']);
    }

    public function test_reorder_requires_reason_and_rbac(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offering();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $appointment = $this->book($offering, '2026-09-04T08:00:00-03:00', '11144477735');
        $this->postJson("/api/v1/team/appointments/{$appointment}/check-in")->assertOk();
        $entry = QueueEntry::query()->firstOrFail();

        $this->postJson("/api/v1/team/queue/{$entry->id}/reorder", ['priority_code' => 'PCD'])
            ->assertUnprocessable()->assertJsonValidationErrors('priority_reason');
        $this->postJson("/api/v1/team/queue/{$entry->id}/reorder", ['priority_code' => 'VIP', 'priority_reason' => 'Código fora da lei'])
            ->assertUnprocessable()->assertJsonValidationErrors('priority_code');

        $this->actingAs(User::factory()->create(['role' => UserRole::Doctor]));
        $this->postJson("/api/v1/team/queue/{$entry->id}/reorder", ['priority_code' => 'PCD', 'priority_reason' => 'Médico não reordena'])
            ->assertForbidden();
    }

    public function test_call_moves_to_called_and_doctor_only_calls_own_patients(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $mine = $this->offering();
        $other = $this->offering();
        $doctor = User::factory()->create(['role' => UserRole::Doctor]);
        $mine->professional->forceFill(['user_id' => $doctor->id])->save();

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $first = $this->book($mine, '2026-09-04T08:00:00-03:00', '11144477735');
        $second = $this->book($other, '2026-09-04T08:00:00-03:00', '12345678909');
        $this->postJson("/api/v1/team/appointments/{$first}/check-in")->assertOk();
        $this->postJson("/api/v1/team/appointments/{$second}/check-in")->assertOk();
        $mineEntry = QueueEntry::query()->where('appointment_id', $first)->firstOrFail();
        $otherEntry = QueueEntry::query()->where('appointment_id', $second)->firstOrFail();

        // Recepção chama qualquer ficha da fila.
        $this->postJson("/api/v1/team/queue/{$otherEntry->id}/call")
            ->assertOk()->assertJsonPath('data.status', 'CALLED');
        $this->assertDatabaseHas('audit_logs', ['entity_key' => $otherEntry->id, 'action' => 'queue.called']);

        // Médico chama o próprio e é barrado no alheio.
        $this->actingAs($doctor);
        $this->postJson("/api/v1/team/queue/{$mineEntry->id}/call")
            ->assertOk()->assertJsonPath('data.status', 'CALLED');
        $this->postJson("/api/v1/team/queue/{$otherEntry->id}/call")->assertUnprocessable();

        $history = $this->actingAs(User::factory()->create(['role' => UserRole::Reception]))
            ->getJson('/api/v1/team/queue/history?date=2026-09-04')->assertOk()->json('data');
        $this->assertCount(2, $history);
        $this->assertSame('A001', $history[0]['ticket']);
    }

    public function test_payment_before_checkin_rejoins_queue_and_history_persists(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offering();
        $cashier = User::factory()->create(['role' => UserRole::Cashier]);
        $this->actingAs($cashier);
        $this->postJson('/api/v1/team/cash-registers', ['unit_id' => $offering->unit_id, 'shift' => 'UNICO', 'float_amount' => '0.00'])->assertCreated();

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $appointment = $this->book($offering, '2026-09-04T08:00:00-03:00', '11144477735');
        $this->actingAs($cashier);
        $this->postJson("/api/v1/team/appointments/{$appointment}/checkout", ['payment_method_code' => 'ESPECIE'])->assertCreated();
        $this->assertDatabaseHas('queue_entries', ['appointment_id' => $appointment, 'status' => 'CHECKOUT']);

        // Pagamento-antes: o check-in devolve a ficha para a fila aguardando chamada.
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->postJson("/api/v1/team/appointments/{$appointment}/check-in")->assertOk();
        $entry = QueueEntry::query()->where('appointment_id', $appointment)->firstOrFail();
        $this->assertSame('WAITING', $entry->status);

        $this->postJson("/api/v1/team/queue/{$entry->id}/call")->assertOk()->assertJsonPath('data.status', 'CALLED');
        $this->postJson("/api/v1/team/appointments/{$appointment}/start-care")->assertOk();
        $this->assertSame('IN_SERVICE', $entry->fresh()->status);

        // Histórico e painel preservam a chamada mesmo após o início do atendimento.
        $history = $this->getJson('/api/v1/team/queue/history?date=2026-09-04')->assertOk()->json('data');
        $this->assertCount(1, $history);
        $this->assertSame('A001', $history[0]['ticket']);
        auth()->forgetGuards();
        $display = $this->getJson("/api/v1/public/display?unit_id={$offering->unit_id}&date=2026-09-04")->assertOk()->json('data');
        $this->assertSame('A001', $display['current']['ticket']);
    }

    public function test_defaults_follow_clinic_today_not_utc(): void
    {
        // 22:30 em Fortaleza já é o dia seguinte em UTC: fila, painel e visão geral
        // precisam continuar olhando o dia da clínica.
        $this->travelTo(CarbonImmutable::parse('2026-09-04T22:30:00-03:00'));
        $offering = $this->offering();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));

        $this->getJson('/api/v1/team/queue')->assertOk()->assertJsonPath('data.date', '2026-09-04');
        $this->getJson('/api/v1/team/queue/history')->assertOk();

        auth()->forgetGuards();
        $this->getJson("/api/v1/public/display?unit_id={$offering->unit_id}")->assertOk()->assertJsonPath('data.date', '2026-09-04');

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.date', '2026-09-04');
    }

    public function test_cancel_removes_entry_from_queue(): void
    {
        // Fantasma da fila: pagamento-antes cria a entrada antes do check-in;
        // após estorno e cancelamento, a ficha não pode aparecer na fila do dia.
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offering();
        $cashier = User::factory()->create(['role' => UserRole::Cashier]);
        $this->actingAs($cashier);
        $this->postJson('/api/v1/team/cash-registers', ['unit_id' => $offering->unit_id, 'shift' => 'UNICO', 'float_amount' => '0.00'])->assertCreated();

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $appointment = $this->book($offering, '2026-09-04T08:00:00-03:00', '11144477735');
        $this->actingAs($cashier);
        $this->postJson("/api/v1/team/appointments/{$appointment}/checkout", ['payment_method_code' => 'ESPECIE'])->assertCreated();
        $this->assertDatabaseCount('queue_entries', 1);

        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]));
        $this->postJson("/api/v1/team/appointments/{$appointment}/void-payment", ['reason' => 'Cobrança indevida'])->assertCreated();

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->patchJson("/api/v1/team/appointments/{$appointment}/cancel", ['reason' => 'Paciente desistiu'])->assertOk();
        $this->assertDatabaseCount('queue_entries', 0);
    }

    private function offering(): ServiceOffering
    {
        $price = ProcedurePrice::factory()->create(['amount' => '150.00']);
        $price->unit->update(['operational_enabled' => true]);
        $price->agreement->update(['is_default' => true, 'requires_authorization' => false]);
        $professional = Professional::factory()->create();
        $link = CatalogLink::create(['unit_id' => $price->unit_id, 'procedure_id' => $price->procedure_id,
            'agreement_id' => $price->agreement_id, 'professional_id' => $professional->id, 'active' => true]);
        $offering = ServiceOffering::factory()->create([
            'unit_id' => $link->unit_id, 'procedure_id' => $link->procedure_id, 'professional_id' => $link->professional_id,
            'agreement_id' => $link->agreement_id, 'catalog_link_id' => $link->id, 'procedure_price_id' => $price->id,
            'price' => '150.00', 'booking_mode' => 'FIXED_SLOT', 'modality' => 'PRESENTIAL',
            'duration_override_minutes' => 30, 'active' => true, 'online_enabled' => false,
        ]);
        $offering->scheduleWindows()->create(['effective_from' => '2026-09-01', 'weekday' => 5,
            'starts_at' => '08:00:00', 'ends_at' => '10:00:00', 'slot_minutes' => 30, 'active' => true]);
        app(MaterializeScheduleOccurrences::class)->handle($offering);

        return $offering;
    }

    private function book(ServiceOffering $offering, string $startsAt, string $cpf): string
    {
        return $this->postJson('/api/v1/team/appointments', [
            'idempotency_key' => (string) Str::uuid(), 'offering_id' => $offering->id, 'starts_at' => $startsAt,
            'patient' => ['name' => 'Paciente Fila', 'cpf' => $cpf, 'phone' => '85999999999', 'birth_date' => '1990-01-01'],
            'privacy_consent' => true,
        ])->assertCreated()->json('data.id');
    }
}
