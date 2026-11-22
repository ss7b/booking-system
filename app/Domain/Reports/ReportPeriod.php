<?php

namespace App\Domain\Reports;

enum ReportPeriod: string
{
    case Today = 'today';
    case CurrentWeek = 'current_week';
    case CurrentMonth = 'current_month';
}
