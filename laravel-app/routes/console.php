<?php

use App\Jobs\UpdateClamAvSignaturesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new UpdateClamAvSignaturesJob)
    ->dailyAt('03:00');
