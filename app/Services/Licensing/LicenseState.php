<?php

namespace App\Services\Licensing;

enum LicenseState: string
{
    case Free = 'free';
    case Licensed = 'licensed';
    case UpdatesExpired = 'updates_expired';
    case Invalid = 'invalid';
}
