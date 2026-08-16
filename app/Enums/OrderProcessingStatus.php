<?php

namespace App\Enums;

enum OrderProcessingStatus: string
{
    case AWAITING_LABEL = 'awaiting_label';
    case LABEL_PRINTED = 'label_printed';
    case SEPARATING = 'separating';
    case SEPARATED = 'separated';
    case AWAITING_SHIPMENT = 'awaiting_shipment';
    case SHIPPED = 'shipped';
}
