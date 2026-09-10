<?php

namespace App\Enums;

enum ProcessingRunKind: string
{
    case Initial = 'initial';
    case Reprocessing = 'reprocessing';
}
