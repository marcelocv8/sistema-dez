<?php

namespace App\Enums;

enum ImportIssueResolutionStatus: string
{
    case Open = 'OPEN';
    case Resolved = 'RESOLVED';
    case Ignored = 'IGNORED';
    case Unmatched = 'UNMATCHED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
