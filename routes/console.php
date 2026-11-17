<?php

declare(strict_types=1);

use App\Jobs\ReconcilePendingGenerations;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new ReconcilePendingGenerations)->everyMinute();
