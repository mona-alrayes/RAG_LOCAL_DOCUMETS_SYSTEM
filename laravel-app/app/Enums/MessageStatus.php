<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
