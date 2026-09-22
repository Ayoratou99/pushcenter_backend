<?php

use App\Services\JwtService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('auth:prune-tokens', function (JwtService $jwt) {
    $deleted = $jwt->pruneExpiredRefreshTokens();

    $this->info("{$deleted} expired or revoked refresh token(s) removed.");
})->purpose('Delete expired and long-revoked refresh tokens');

// Keep the refresh_tokens table from growing without bound.
Schedule::command('auth:prune-tokens')->daily();

// Meta approves or rejects WhatsApp templates within minutes to hours.
Schedule::command('whatsapp:sync-templates')->everyFifteenMinutes()->withoutOverlapping();

// Who started (or blocked) each application's Telegram bot.
Schedule::command('telegram:poll')->everyMinute()->withoutOverlapping();
