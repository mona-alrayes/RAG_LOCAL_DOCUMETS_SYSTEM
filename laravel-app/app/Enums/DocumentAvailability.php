<?php

namespace App\Enums;

enum DocumentAvailability: string
{
    case Pending = 'pending';
    case Scanning = 'scanning';
    case Infected = 'infected';
    case Queued = 'queued';
    case Processing = 'processing';
    case Indexing = 'indexing';
    case Ready = 'ready';
    case Failed = 'failed';
}
