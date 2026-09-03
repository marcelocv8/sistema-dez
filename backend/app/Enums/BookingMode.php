<?php

namespace App\Enums;

enum BookingMode: string
{
    case FixedSlot = 'FIXED_SLOT';
    case ArrivalBlock = 'ARRIVAL_BLOCK';
    case WalkInInfo = 'WALK_IN_INFO';
}
