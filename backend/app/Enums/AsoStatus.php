<?php

namespace App\Enums;

enum AsoStatus: string
{
    case Pendente = 'PENDENTE';
    case Agendado = 'AGENDADO';
    case Emitido = 'EMITIDO';
    case Cancelado = 'CANCELADO';

    public function label(): string
    {
        return match ($this) {
            self::Pendente => 'Pendente',
            self::Agendado => 'Agendado',
            self::Emitido => 'Emitido',
            self::Cancelado => 'Cancelado',
        };
    }
}
