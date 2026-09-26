<?php

namespace App\Console\Commands;

use App\Services\PassPurchaseService;
use Illuminate\Console\Command;

/** php artisan pass:release-expired-reservations — frees Grand Opening slots abandoned mid-checkout. */
class ReleaseExpiredPassReservations extends Command
{
    protected $signature = 'pass:release-expired-reservations';

    protected $description = 'Cancel payment_pending passes past their reservation window and free their slot.';

    public function handle(PassPurchaseService $purchases): int
    {
        $count = $purchases->releaseExpiredReservations();
        $this->info("Released {$count} expired pass reservation(s).");

        return self::SUCCESS;
    }
}
