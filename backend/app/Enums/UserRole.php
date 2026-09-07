<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'ADMIN';
    case Supervisor = 'SUPERVISOR';
    case Reception = 'RECEPTION';
    case Cashier = 'CASHIER';
    case Manager = 'MANAGER';
    case Doctor = 'DOCTOR';

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
                'queue.call',
                'cash.view',
                'cash.operate',
                'cash.close',
                'discounts.validate',
                'payouts.view',
                'payouts.manage',
                'payouts.confirm',
                'payouts.pay',
                'b2b.view',
                'b2b.manage',
                'b2b.settle',
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
                'queue.call',
                'cash.view',
                'cash.close',
                'discounts.validate',
                'payouts.view',
                'payouts.manage',
                'b2b.view',
                'b2b.manage',
            ],
            self::Reception => ['prices.view', 'catalog.view', 'catalog-links.view', 'schedule.view', 'appointments.manage', 'queue.call', 'b2b.view'],
            self::Cashier => ['prices.view', 'schedule.view', 'catalog-links.view', 'cash.view', 'cash.operate', 'cash.close', 'payouts.view', 'payouts.pay', 'b2b.view', 'b2b.settle'],
            self::Manager => [
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
                'queue.call',
                'cash.view',
                'cash.operate',
                'cash.close',
                'discounts.validate',
                'payouts.view',
                'payouts.manage',
                'payouts.confirm',
                'payouts.pay',
                'b2b.view',
                'b2b.manage',
                'b2b.settle',
            ],
            self::Doctor => ['schedule.view', 'catalog-links.view', 'queue.call', 'payouts.view', 'payouts.confirm'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Supervisor => 'Supervisor',
            self::Reception => 'Recepção',
            self::Cashier => 'Caixa',
            self::Manager => 'Gerente',
            self::Doctor => 'Médico',
        };
    }
}
