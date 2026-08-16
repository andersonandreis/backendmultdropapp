<?php

namespace App\Enums;

enum SyncStatus: string
{
    case PENDING = 'pending';
    case SYNCED = 'synced';
    case ERROR = 'error';
    case PAUSED = 'paused';
}
