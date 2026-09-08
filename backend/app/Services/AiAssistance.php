<?php

namespace App\Services;

use App\Models\AiRequest;
use App\Models\Appointment;
use App\Models\Patient;
use Illuminate\Support\Facades\Http;

class AiAssistance
{
    public const MARKER = '<p><strong>SUGESTÃO DE IA — rascunho, revisar antes de validar.</strong></p>';

    public function enabled(): bool
    {
        return is_string(config('sistema_dez.deepseek_api_key')) && config('sistema_dez.deepseek_api_key') !== '';
    }

    public function model(): string
    {
        return (string) config('sistema_dez.deepseek_model', 'deepseek-chat');
    }

    /**
     * Gera uma sugestão de conduta com base no contexto do paciente. Nunca é
     * gravada no prontuário automaticamente; o médico decide se insere e o
     * conteúdo entra sempre como rascunho marcado (T18, §6.18).
     *
     * @return array{ai_request: AiRequest, response_text: string|null}
     */
    public function suggest(Patient $patient, ?Appointment $appointment, string $question, int $userId): array
    {
        $prompt = $this->buildPrompt($patient, $appointment, $question);
        $aiRequest = AiRequest::query()->create([
            'patient_id' => $patient->id,
            'appointment_id' => $appointment?->id,
            'user_id' => $userId,
            'model' => $this->model(),
            'prompt_summary' => mb_substr($question, 0, 2000),
            'status' => AiRequest::STATUS_ERROR,
        ]);

        if (! $this->enabled()) {
            $aiRequest->forceFill(['error_message' => 'IA não configurada (DEEPSEEK_API_KEY ausente).'])->save();

            return ['ai_request' => $aiRequest, 'response_text' => null];
        }

        try {
            $response = Http::timeout(60)
                ->withToken((string) config('sistema_dez.deepseek_api_key'))
                ->acceptJson()
                ->post('https://api.deepseek.com/chat/completions', [
                    'model' => $this->model(),
                    'messages' => [
                        ['role' => 'system', 'content' => 'Você é um assistente clínico que sugere condutas para um médico revisar. Responda em português do Brasil, de forma objetiva, em HTML simples (parágrafos e listas). Nunca substitua o julgamento clínico; sinalize incertezas.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => 1200,
                ]);
            $text = $response->json('choices.0.message.content');
            if (! $response->successful() || ! is_string($text) || $text === '') {
                $aiRequest->forceFill(['error_message' => mb_substr((string) ($response->json('error.message') ?? $response->body()), 0, 480)])->save();

                return ['ai_request' => $aiRequest, 'response_text' => null];
            }
            $aiRequest->forceFill(['response_text' => $text, 'status' => AiRequest::STATUS_OK])->save();

            return ['ai_request' => $aiRequest, 'response_text' => $text];
        } catch (\Throwable $exception) {
            $aiRequest->forceFill(['error_message' => mb_substr($exception->getMessage(), 0, 480)])->save();

            return ['ai_request' => $aiRequest, 'response_text' => null];
        }
    }

    private function buildPrompt(Patient $patient, ?Appointment $appointment, string $question): string
    {
        $age = $patient->birth_date !== null ? (int) now(config('sistema_dez.timezone'))->diffInYears($patient->birth_date) : null;
        $context = 'PACIENTE: '.($patient->name ?? '—');
        $context .= $age !== null ? " ({$age} anos)" : '';
        $alerts = $patient->alerts()->where('active', true)->get(['kind', 'title', 'detail']);
        if ($alerts->isNotEmpty()) {
            $context .= "\nALERTAS: ".$alerts->map(fn ($alert) => "[{$alert->kind}] {$alert->title}".($alert->detail ? " ({$alert->detail})" : ''))->implode('; ');
        }
        if ($appointment !== null) {
            $context .= "\nCONSULTA: ".($appointment->scheduled_at?->format('d/m/Y') ?? '—');
        }
        $context .= "\nPERGUNTA DO MÉDICO: {$question}";

        return $context;
    }
}
