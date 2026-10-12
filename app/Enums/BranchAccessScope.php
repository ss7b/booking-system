<?php

namespace App\Enums;

enum BranchAccessScope: string
{
    case All = 'all';
    case Selected = 'selected';
}
