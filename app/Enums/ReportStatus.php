<?php

declare(strict_types=1);

namespace App\Enums;

enum ReportStatus: string
{
    case Pending = 'pending';
    case Building = 'building';
    case Sent = 'sent';
    case Partial = 'partial';
    case Failed = 'failed';
}
