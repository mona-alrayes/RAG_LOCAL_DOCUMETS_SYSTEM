<?php

namespace App\Enums;

enum ProcessingRunEvent: string
{
    case IndexingStarted = 'indexing_started';
}
