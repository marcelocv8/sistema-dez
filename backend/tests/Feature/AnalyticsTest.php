<?php

namespace Tests\Feature;

use App\Enums\AsoType;
use App\Enums\UserRole;
use App\Models\Agreement;
use App\Models\Appointment;
use App\Models\B2bInvoice;
use App\Models\MtAso;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_manager_reads_kpis_and_rbac_blocks_reception(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-07T10:00:00-03:00'));
        $agreement = Agreement::factory()->create();
        $otherAgreement = Agreement::factory()->create();

        $paid = Appointment::factory()->create([
            'scheduled_at' => '2026-09-05T12:00:00Z',
            'status' => 'DONE',
            'payment_status' => 'PAID',
            'paid_at' => '2026-09-05T12:00:00Z',
            'price_snapshot' => '200.00',
        ]);
        $paid->serviceOffering->agreement_id = $agreement->id;
        $paid->serviceOffering->save();

        $noShow = Appointment::factory()->create([
            'scheduled_at' => '2026-09-05T15:00:00Z',
            'status' => 'NO_SHOW',
            'payment_status' => 'PENDING',
            'price_snapshot' => '100.00',
        ]);
        $noShow->serviceOffering->agreement_id = $agreement->id;
        $noShow->serviceOffering->save();

        B2bInvoice::query()->create([
            'agreement_id' => $otherAgreement->id,
            'competence' => '2026-08-01',
            'status' => 'ENVIADA',
            'subtotal_gross' => '500.00',
            'settled_amount' => '100.00',
            'issued_at' => CarbonImmutable::parse('2026-08-01T10:00:00-03:00'),
            'due_at' => '2026-08-20',
            'created_by' => User::factory()->create()->id,
        ]);

        MtAso::factory()->emitted()->create(['agreement_id' => $agreement->id, 'expires_at' => now()->addDays(10)->toDateString()]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Manager]));
        $data = $this->getJson('/api/v1/team/analytics')->assertOk()->json('data');

        $this->assertSame(2, $data['appointments']['scheduled']);
        $this->assertSame(1, $data['appointments']['done']);
        $this->assertSame(1, $data['appointments']['no_show']);
        $this->assertSame(50.0, (float) $data['appointments']['no_show_rate']);
        $this->assertSame('200.00', $data['revenue']);
        $this->assertSame('200.00', $data['ticket_avg']);
        $this->assertSame(400.0, (float) $data['b2b_open']['aging']['vencida_30']);
        $this->assertSame(1, $data['asos_due']);
        $this->assertSame(1, count($data['production_by_doctor']));
        $this->assertSame(200.0, (float) $data['production_by_doctor'][0]['revenue']);

        $filtered = $this->getJson('/api/v1/team/analytics?agreement_id='.$otherAgreement->id)->assertOk()->json('data');
        $this->assertSame(0.0, (float) $filtered['revenue']);
        $this->assertSame(400.0, (float) $filtered['b2b_open']['aging']['vencida_30']);

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->getJson('/api/v1/team/analytics')->assertForbidden();
    }

    public function test_export_streams_csv(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-07T10:00:00-03:00'));
        $paid = Appointment::factory()->create([
            'scheduled_at' => '2026-09-05T12:00:00Z',
            'status' => 'DONE',
            'payment_status' => 'PAID',
            'paid_at' => '2026-09-05T12:00:00Z',
            'price_snapshot' => '250.00',
        ]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $response = $this->getJson('/api/v1/team/analytics/export?type=revenue');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $body = $response->streamedContent();
        $this->assertStringContainsString('convenio', $body);
        $this->assertStringContainsString('250.00', $body);
    }

    public function test_aso_due_export_lists_employees(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-07T10:00:00-03:00'));
        MtAso::factory()->emitted()->create(['type' => AsoType::Periodico, 'expires_at' => now()->addDays(15)->toDateString()]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $response = $this->getJson('/api/v1/team/analytics/export?type=asos_due')->assertOk();
        $body = $response->streamedContent();
        $this->assertStringContainsString('Periódico', $body);
        $this->assertStringContainsString('EMITIDO', $body);
    }
}
