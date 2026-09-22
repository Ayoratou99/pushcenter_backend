<?php

namespace App\Console\Commands;

use App\Models\TelegramSetting;
use App\Services\Telegram\TelegramException;
use App\Services\Telegram\TelegramUpdates;
use Illuminate\Console\Command;

/**
 * Reads who started (or blocked) each application's bot. Scheduled every
 * minute: a person who pressed Start can receive messages a minute later.
 */
class PollTelegramUpdatesCommand extends Command
{
    protected $signature = 'telegram:poll {--business= : Only this application (id)}';

    protected $description = 'Record the new Telegram subscriptions of every bot';

    public function handle(TelegramUpdates $updates): int
    {
        $settings = TelegramSetting::with('business')
            ->where('test_status', 'success')
            ->when($this->option('business'), fn ($query, $id) => $query->where('business_id', $id))
            ->get();

        foreach ($settings as $setting) {
            try {
                $counts = $updates->poll($setting);

                if ($counts['updates'] > 0) {
                    $this->info(sprintf(
                        '%s: %d update(s), %d subscription(s), %d unsubscription(s)',
                        $setting->business?->name ?? "#{$setting->business_id}",
                        $counts['updates'],
                        $counts['subscribed'],
                        $counts['blocked']
                    ));
                }
            } catch (TelegramException $e) {
                $this->warn(($setting->business?->name ?? "#{$setting->business_id}") . ': ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
