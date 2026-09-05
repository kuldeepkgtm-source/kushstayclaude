<?php

namespace App\Console\Commands;

use App\Services\HoldService;
use Illuminate\Console\Command;

/**
 * php artisan holds:purge — server-timed hold expiry, run every minute via the scheduler.
 * Also invoked inline before availability checks, but a scheduled sweep keeps the table small
 * and means an expired hold disappears even if nobody happens to query availability right then.
 */
class PurgeExpiredHolds extends Command
{
    protected $signature = 'holds:purge';

    protected $description = 'Delete holds whose expires_at has passed, per the database server clock.';

    public function handle(HoldService $holds): int
    {
        $count = $holds->purgeExpired();
        $this->info("Purged {$count} expired hold(s).");

        return self::SUCCESS;
    }
}
