<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case ACTIVE = 'active';
    case TRIAL = 'trial';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';
}
