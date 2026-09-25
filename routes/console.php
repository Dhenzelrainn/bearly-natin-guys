<?php

use App\Services\AnnouncementPublishingService;
use Illuminate\Support\Facades\Schedule;

Schedule::call(fn () => app(AnnouncementPublishingService::class)->process())
    ->name('process-announcement-schedule')
    ->everyMinute()
    ->withoutOverlapping();
