<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Procedure;
use App\Models\Professional;
use App\Models\ProfessionalProcedureCommission;
use App\Services\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProfessionalCommissionController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function mine(Request $request): JsonResponse
    {
        $professional = Professional::query()->where('user_id', $request->user()->getAuthIdentifier())->first();
        abort_unless($professional, 404, 'Médico sem vínculo com profissional.');

        return response()->json(['data' => $professional->only(['id', 'name', 'commission_type', 'commission_value'])]);
    }

    public function index(Request $request, Professional $professional): JsonResponse
    {
        $commissions = ProfessionalProcedureCommission::query()->with(['procedure:id,name'])
            ->where('professional_id', $professional->id)->orderBy('created_at')->get();

        return response()->json(['data' => $commissions]);
    }

    public function store(Request $request, Professional $professional): JsonResponse
    {
        $data = $request->validate([
            'procedure_id' => ['required', 'uuid', 'exists:procedures,id'],
            'commission_type' => ['required', Rule::in(['PERCENT', 'FIXED'])],
            'commission_value' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);
        if ($data['commission_type'] === 'PERCENT' && (float) $data['commission_value'] > 100) {
            return response()->json(['message' => 'Percentual de comissão não pode passar de 100.', 'errors' => ['commission_value' => ['Percentual de comissão não pode passar de 100.']]], 422);
        }

        $commission = DB::transaction(function () use ($request, $professional, $data): ProfessionalProcedureCommission {
            Procedure::query()->whereKey($data['procedure_id'])->where('active', true)->firstOrFail();
            $commission = ProfessionalProcedureCommission::query()->updateOrCreate(
                ['professional_id' => $professional->id, 'procedure_id' => $data['procedure_id']],
                ['commission_type' => $data['commission_type'], 'commission_value' => (string) $data['commission_value']]
            );
            $this->audit->record($request, $commission, 'commission.saved', null, $commission->attributesToArray());

            return $commission;
        });

        return response()->json(['data' => $commission->load('procedure:id,name')], 201);
    }

    public function destroy(Request $request, Professional $professional, ProfessionalProcedureCommission $commission): JsonResponse
    {
        abort_unless($commission->professional_id === $professional->id, 404);
        $before = $commission->attributesToArray();
        DB::transaction(function () use ($request, $commission, $before): void {
            $commission->delete();
            $this->audit->record($request, $commission, 'commission.removed', $before, null);
        });

        return response()->json(['data' => ['id' => $commission->id]]);
    }
}
