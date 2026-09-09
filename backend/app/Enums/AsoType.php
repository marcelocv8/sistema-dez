<?php

namespace App\Enums;

enum AsoType: string
{
    case Admissional = 'ADMISSIONAL';
    case Periodico = 'PERIODICO';
    case RetornoAoTrabalho = 'RETORNO_AO_TRABALHO';
    case MudancaDeFuncao = 'MUDANCA_DE_FUNCAO';
    case Demissional = 'DEMISSIONAL';

    /** Mapa dos valores usados no STG legado (com acento) para os enum keys. */
    public const LEGACY_MAP = [
        'ADMISSIONAL' => 'ADMISSIONAL',
        'PERÍODICO' => 'PERIODICO',
        'PERIODICO' => 'PERIODICO',
        'RETORNO AO TRABALHO' => 'RETORNO_AO_TRABALHO',
        'MUDANÇA DE FUNÇÃO' => 'MUDANCA_DE_FUNCAO',
        'MUDANCA DE FUNCAO' => 'MUDANCA_DE_FUNCAO',
        'DEMISSIONAL' => 'DEMISSIONAL',
    ];

    public function label(): string
    {
        return match ($this) {
            self::Admissional => 'Admissional',
            self::Periodico => 'Periódico',
            self::RetornoAoTrabalho => 'Retorno ao trabalho',
            self::MudancaDeFuncao => 'Mudança de função',
            self::Demissional => 'Demissional',
        };
    }
}
