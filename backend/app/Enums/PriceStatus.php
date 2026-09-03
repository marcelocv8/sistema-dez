<?php

namespace App\Enums;

enum PriceStatus: string
{
    case Valid = 'VALID';
    case Quarantined = 'QUARANTINED';
}
