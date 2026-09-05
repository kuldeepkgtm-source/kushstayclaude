<?php

namespace App\Console\Commands;

use App\Models\CalendarSource;
use App\Services\IcalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * php artisan ical:sync
 * Registered on Laravel's scheduler (routes/console.php) to run every N minutes via the single
 * cPanel cron entry that calls `php artisan schedule:run`. This is the real, server-side
 * replacement for the prototype's manual "Sync Now" button — the browser artifact cannot do this
 * itself since it isn't always open.
 */
class IcalSync extends Command
{
    protected $signature = 'ical:sync {--source= : Sync only this calendar_sources.id}';

    protected $description = 'Pull each active calendar source\'s iCal URL and reconcile events into bookings.';

    public function handle(IcalService $ical): int
    {
        $sources = CalendarSource::where('status', 'Active')
            ->where('type', 'ical')->whereNotNull('ical_url')
            ->when($this->option('source'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        foreach ($sources as $source) {
            $this->info("Syncing {$source->name}...");
            try {
                $response = Http::timeout(15)->get($source->ical_url);
                if (! $response->successful()) {
                    $this->warn("  fetch failed: HTTP {$response->status()}");

                    continue;
                }
                $events = $ical->parse($response->body());
                $report = $ical->importEvents($source, $events);
                $ical->reconcileCancellations($source, array_column($events, 'uid'));
                $this->info("  created={$report['created']} updated={$report['updated']} skipped={$report['skipped']}");
            } catch (\Throwable $e) {
                $this->error("  {$source->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
