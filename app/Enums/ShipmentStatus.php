<?php

namespace App\Enums;

enum ShipmentStatus: string
{
    case DRAFT = 'draft';
    case SENT = 'sent';
    case IN_TRANSIT = 'in_transit';
    case RECEIVED = 'received';
    case CHECKING = 'checking';
    case CHECKED = 'checked';
}
