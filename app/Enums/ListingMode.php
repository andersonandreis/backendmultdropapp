<?php

namespace App\Enums;

enum ListingMode: string
{
    case MANUAL = 'manual';
    case SEMI_AUTO = 'semi_auto';
    case FULL_AUTO = 'full_auto';
}
