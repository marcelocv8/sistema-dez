<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'ADMIN';
    case Supervisor = 'SUPERVISOR';
    case Reception = 'RECEPTION';

    /** @return list<string> */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => [
                'prices.view',
                'catalog.view',
                'catalog.manage',
                'catalog-links.view',
                'catalog-links.manage',
                'team.view',
                'team.manage',
                'imports.view',
                'imports.resolve',
                'audit.view',
                'schedule.view',
                'schedule.manage',
                'appointments.manage',
            ],
            self::Supervisor => [
                'prices.view',
                'catalog.view',
                'catalog.manage',
                'catalog-links.view',
                'catalog-links.manage',
                'imports.view',
                'imports.resolve',
                'audit.view',
                'schedule.view',
                'schedule.manage',
                'appointments.manage',
            ],
            self::Reception => ['prices.view', 'catalog.view', 'catalog-links.view', 'schedule.view', 'appointments.manage'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Supervisor => 'Supervisor',
            self::Reception => 'Recepção',
        };
    }
}
