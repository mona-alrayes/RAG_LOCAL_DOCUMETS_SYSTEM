<?php

namespace App\Enums;

enum DocumentSecurityScanStatus: string
{
    case Clean = 'clean';
    case Infected = 'infected';
    case ScanFailed = 'scan_failed';
}
