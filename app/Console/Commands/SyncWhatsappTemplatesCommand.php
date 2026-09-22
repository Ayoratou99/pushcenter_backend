<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\WhatsappTemplate;
use App\Services\AyosPush\AyosPushException;
use App\Services\AyosPush\AyosPushTemplateService;
use Illuminate\Console\Command;

/**
 * Picks up Meta's decision on submitted templates.
 *
 * Scheduled every 15 minutes for the applications that have a template
 * waiting for approval. Every AyosPush API call is billed to the AyosPush
 * account, so the others are left alone unless asked for.
 */
class SyncWhatsappTemplatesCommand extends Command
{
    protected $signature = 'whatsapp:sync-templates
        {--business= : Only this application (id)}
        {--all : Every configured application, not only those with pending templates}
        {--import : Also copy the templates that only exist on AyosPush}';

    protected $description = 'Synchronise WhatsApp template statuses with AyosPush';

    public function handle(AyosPushTemplateService $service): int
    {
        $query = Business::query()->whereHas('whatsappSetting');

        if ($businessId = $this->option('business')) {
            $query->whereKey($businessId);
        } elseif (! $this->option('all')) {
            $query->whereIn('id', WhatsappTemplate::query()
                ->where('status', 'pending')
                ->whereNotNull('provider_template_id')
                ->select('business_id'));
        }

        $businesses = $query->get();

        if ($businesses->isEmpty()) {
            $this->info('Nothing to synchronise.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($businesses as $business) {
            try {
                $counts = $service->syncBusiness($business, (bool) $this->option('import'));
                $this->info(sprintf(
                    '%s: %d updated, %d imported, %d unchanged, %d missing',
                    $business->name,
                    $counts['updated'],
                    $counts['imported'],
                    $counts['unchanged'],
                    $counts['missing']
                ));
            } catch (AyosPushException $e) {
                $failures++;
                $this->warn("{$business->name}: {$e->getMessage()}");
            }
        }

        return $failures === $businesses->count() ? self::FAILURE : self::SUCCESS;
    }
}
