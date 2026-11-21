<?php

declare(strict_types=1);

namespace App\Enums;

enum ReportType: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function title(): string
    {
        return ucfirst($this->value).' Traffic Report';
    }
}
