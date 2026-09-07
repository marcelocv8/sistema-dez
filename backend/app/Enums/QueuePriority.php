<?php

namespace App\Enums;

enum QueuePriority: string
{
    case Pcd = 'PCD';
    case Gestante = 'GESTANTE';
    case Lactante = 'LACTANTE';
    case Colo = 'COLO';
    case Obeso = 'OBESO';
    case Idoso60 = 'IDOSO60';
    case Idoso80 = 'IDOSO80';

    public function rank(): int
    {
        return match ($this) {
            self::Idoso80 => 1,
            default => 2,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pcd => 'PCD',
            self::Gestante => 'Gestante',
            self::Lactante => 'Lactante',
            self::Colo => 'Criança de colo',
            self::Obeso => 'Obeso',
            self::Idoso60 => 'Idoso 60+',
            self::Idoso80 => 'Idoso 80+ (sub-prioridade)',
        };
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
