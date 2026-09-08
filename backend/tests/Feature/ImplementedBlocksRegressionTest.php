<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\ReportController;
use App\Models\Agreement;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\ChatConversation;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProcedurePrice;
use App\Models\Professional;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Models\Unit;
use App\Models\User;
use App\Services\ClinicalHtml;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImplementedBlocksRegressionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function report(array $attributes = []): Report
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $appointment = Appointment::factory()->create(['status' => 'CHECKED_IN', 'payment_status' => 'PAID', 'care_started_at' => now(), ...$attributes]);
        $template = ReportTemplate::factory()->create(['content_html' => '<p>Original</p>', 'doctor_id' => null, 'procedure_id' => null]);
        $id = $this->postJson('/api/v1/team/reports', ['appointment_id' => $appointment->id, 'template_id' => $template->id])->assertCreated()->json('data.id');

        return Report::query()->findOrFail($id);
    }

    public function test_html_is_sanitized_in_writes_legacy_reads_and_portal(): void
    {
        $report = $this->report();
        $unsafe = '<p onclick="alert(1)" style="text-align:center;background:url(https://bad.test)"><strong>Seguro</strong><img src=x onerror="alert(1)"><script>alert(1)</script><svg onload="alert(1)"></svg></p>';
        $expected = '<p style="text-align:center"><strong>Seguro</strong></p>';
        $this->putJson('/api/v1/team/reports/'.$report->id, ['content_html' => $unsafe])->assertOk()->assertJsonPath('data.content_html', $expected);
        $this->assertSame($expected, DB::table('reports')->where('id', $report->id)->value('content_html'));
        DB::table('reports')->where('id', $report->id)->update(['content_html' => $unsafe]);
        $this->getJson('/api/v1/team/reports/'.$report->id)->assertOk()->assertJsonPath('data.content_html', $expected);
        $this->postJson('/api/v1/team/reports/'.$report->id.'/finalize')->assertOk();
        $credential = $this->postJson('/api/v1/team/reports/'.$report->id.'/portal')->assertOk()->json('data');
        $token = $this->postJson('/api/v1/public/portal/login', ['login' => $credential['login'], 'password' => $credential['password']])->assertOk()->json('data.token');
        $this->getJson('/api/v1/public/portal/results', ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonPath('data.results.0.content_html', $expected);
        $this->postJson('/api/v1/team/prontuario/notes', ['appointment_id' => $report->appointment_id, 'content_html' => $unsafe])->assertCreated()->assertJsonPath('data.content_html', $expected);
        $this->assertSame('<table><tr><td colspan="2"><u>A</u></td></tr></table>', ClinicalHtml::clean('<table><tr><td colspan="2"><u>A</u></td></tr></table>'));
    }

    public function test_unpaid_unstarted_and_cancelled_appointments_cannot_complete_reports(): void
    {
        foreach ([['payment_status' => 'PENDING'], ['care_started_at' => null], ['status' => 'CONFIRMED']] as $attributes) {
            $report = $this->report($attributes);
            $this->postJson('/api/v1/team/reports/'.$report->id.'/finalize')->assertUnprocessable();
            $this->assertSame('RASCUNHO', $report->fresh()->status);
        }
        $report = $this->report();
        $this->postJson('/api/v1/team/reports/'.$report->id.'/finalize')->assertOk();
        $report->appointment->update(['status' => 'CANCELLED']);
        $this->postJson('/api/v1/team/reports/'.$report->id.'/portal')->assertUnprocessable();
        $this->postJson('/api/v1/team/reports', ['appointment_id' => $report->appointment_id, 'template_id' => $report->template_id])->assertUnprocessable();
    }

    public function test_b2b_authorized_care_can_finalize_and_publish(): void
    {
        $report = $this->report(['payment_status' => 'AUTHORIZED']);
        $this->postJson('/api/v1/team/reports/'.$report->id.'/finalize')->assertOk();
        $this->postJson('/api/v1/team/reports/'.$report->id.'/portal')->assertOk();
    }

    public function test_stale_draft_update_cannot_overwrite_finalized_report(): void
    {
        $stale = $this->report();
        $this->postJson('/api/v1/team/reports/'.$stale->id.'/finalize')->assertOk();
        $request = Request::create('/review', 'PUT', ['content_html' => '<p>Late overwrite</p>']);
        $request->setUserResolver(fn () => auth()->user());
        try {
            app(ReportController::class)->update($request, $stale);
            $this->fail('Expected stale draft rejection');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('report', $exception->errors());
        }
        $this->assertSame('<p>Original</p>', $stale->fresh()->content_html);
        $this->assertSame('<p>Original</p>', $stale->fresh()->content_backup);
    }

    public function test_age_is_positive_in_model_print_and_ai_context(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08T12:00:00-03:00'));
        $report = $this->report();
        $report->patient->update(['birth_date' => '1990-05-10']);
        $report->template->update(['content_html' => '<p>{{PACIENTE_IDADE}} anos</p>']);
        $this->postJson('/api/v1/team/reports', ['appointment_id' => $report->appointment_id, 'template_id' => $report->template_id])->assertCreated()->assertJsonPath('data.content_html', '<p>36 anos</p>');
        $html = $this->postJson('/api/v1/team/reports/'.$report->id.'/print')->assertOk()->json('data.print_html');
        $this->assertStringContainsString('Idade:</strong> 36', $html);
        config(['sistema_dez.deepseek_api_key' => 'test-key']);
        Http::preventStrayRequests();
        Http::fake(['api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => 'Sugestão de teste']]]])]);
        $this->postJson('/api/v1/team/prontuario/ai/suggest', ['patient_id' => $report->patient_id, 'question' => 'Contexto de teste'])->assertOk();
        Http::assertSent(fn ($request) => str_contains($request['messages'][1]['content'], '(36 anos)'));
    }

    public function test_ai_rejects_appointment_from_another_patient(): void
    {
        $report = $this->report();
        config(['sistema_dez.deepseek_api_key' => 'test-key']);
        Http::preventStrayRequests();
        Http::fake();
        $this->postJson('/api/v1/team/prontuario/ai/suggest', ['patient_id' => Patient::factory()->create()->id, 'appointment_id' => $report->appointment_id, 'question' => 'Contexto de teste'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_wrong_doctor_or_procedure_template_is_rejected(): void
    {
        $report = $this->report();
        $wrong = ReportTemplate::factory()->create(['procedure_id' => Procedure::factory()->create()->id]);
        $this->postJson('/api/v1/team/reports', ['appointment_id' => $report->appointment_id, 'template_id' => $wrong->id])->assertUnprocessable();
        $wrong->update(['procedure_id' => null, 'doctor_id' => Professional::factory()->create()->id]);
        $this->postJson('/api/v1/team/reports', ['appointment_id' => $report->appointment_id, 'template_id' => $wrong->id])->assertUnprocessable();
    }

    public function test_chat_loads_recent_messages_and_paginates_without_losing_ties(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user);
        $id = $this->postJson('/api/v1/team/chat/conversations', ['type' => 'DIRECT', 'participant_ids' => [$other->id]])->assertCreated()->json('data.id');
        $conversation = ChatConversation::query()->findOrFail($id);
        for ($i = 1; $i <= 201; $i++) {
            $conversation->messages()->create(['user_id' => $other->id, 'body' => 'message '.$i, 'created_at' => '2026-09-08 12:00:00.123456']);
        }
        $latest = $this->getJson('/api/v1/team/chat/conversations/'.$id.'/messages')->assertOk()->json('data');
        $this->assertCount(200, $latest);
        $older = $this->getJson('/api/v1/team/chat/conversations/'.$id.'/messages?before_id='.$latest[0]['id'])->assertOk()->json('data');
        $this->assertCount(1, $older);
        $this->assertCount(201, array_unique(array_column([...$older, ...$latest], 'id')));
        $this->assertStringContainsString('.123456', $latest[0]['created_at']);
        $conversation->messages()->create(['user_id' => $other->id, 'body' => 'newest', 'created_at' => '2026-09-08 12:01:00']);
        $latest = $this->getJson('/api/v1/team/chat/conversations/'.$id.'/messages')->assertOk()->json('data');
        $this->assertSame('newest', $latest[199]['body']);
    }

    public function test_budget_prices_enforce_dates_unit_and_source_amount(): void
    {
        $this->actingAs(User::factory()->create());
        $price = ProcedurePrice::factory()->create(['amount' => '130.00']);
        foreach ([['valid_until' => now()->subDay()], ['valid_from' => now()->addDay()], ['unit_id' => Unit::factory()->create()->id]] as $override) {
            ProcedurePrice::factory()->create(['procedure_id' => $price->procedure_id, 'agreement_id' => $price->agreement_id, 'unit_id' => $price->unit_id, ...$override]);
        }
        $options = $this->getJson('/api/v1/team/budgets/price-options?agreement_id='.$price->agreement_id.'&unit_id='.$price->unit_id)->assertOk()->json('data');
        $this->assertCount(1, $options);
        $this->assertSame($price->id, $options[0]['id']);
        $input = ['patient_id' => Patient::factory()->create()->id, 'agreement_id' => $price->agreement_id, 'unit_id' => $price->unit_id,
            'items' => [['procedure_price_id' => $price->id, 'procedure_id' => $price->procedure_id, 'amount' => '1.00']]];
        $this->postJson('/api/v1/team/budgets', $input)->assertUnprocessable();
        $input['items'][0]['amount'] = '130.00';
        $this->postJson('/api/v1/team/budgets', $input)->assertCreated()->assertJsonPath('data.subtotal_gross', '130.00');
        $this->assertDatabaseHas('budget_items', ['procedure_price_id' => $price->id, 'amount' => '130.00']);
        $input['agreement_id'] = Agreement::factory()->create()->id;
        $this->postJson('/api/v1/team/budgets', $input)->assertUnprocessable();
        $price->update(['valid_until' => now()->subDay()]);
        $input['agreement_id'] = $price->agreement_id;
        $this->postJson('/api/v1/team/budgets', $input)->assertUnprocessable();
    }

    public function test_expurgo_is_atomic_idempotent_and_removes_clinical_copies(): void
    {
        $report = $this->report();
        $this->postJson('/api/v1/team/reports/'.$report->id.'/finalize')->assertOk();
        $report->refresh()->update(['retention_until' => now()->subDay()]);
        $audit = AuditLog::query()->where('entity_key', $report->id)->firstOrFail();
        $audit->update(['before' => ['content_html' => 'old secret'], 'after' => ['content_backup' => 'old backup', 'adendo' => 'old adendo']]);
        $this->artisan('reports:purge-expired --dry-run')->assertSuccessful();
        $this->assertSame('<p>Original</p>', $report->fresh()->content_html);
        $this->artisan('reports:purge-expired --commit')->assertSuccessful();
        $this->assertSame('', $report->fresh()->content_html);
        $this->assertNull($report->fresh()->content_backup);
        $this->assertNotNull($report->fresh()->purged_at);
        $this->assertArrayNotHasKey('content_html', $audit->fresh()->before);
        $this->assertArrayNotHasKey('content_backup', $audit->fresh()->after);
        $this->assertSame($audit->action, $audit->fresh()->action);
        $this->artisan('reports:purge-expired --commit')->assertSuccessful();
        $this->assertSame(1, $report->events()->where('event', 'RETENCAO_LIMPA')->count());
        $this->postJson('/api/v1/team/reports/'.$report->id.'/portal')->assertUnprocessable();
        $this->postJson('/api/v1/team/reports/'.$report->id.'/append', ['adendo' => 'Não ressuscitar'])->assertUnprocessable();
    }
}
