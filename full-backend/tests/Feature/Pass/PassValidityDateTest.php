<?php

namespace Tests\Feature\Pass;

use Illuminate\Support\Carbon;

/** Validity = activation date through the day before the same calendar date one year later. */
class PassValidityDateTest extends PassTestCase
{
    public function test_expiry_is_one_year_minus_one_day_from_activation(): void
    {
        Carbon::setTestNow('2026-09-06 10:00:00');
        $pass = $this->purchaseActivePass('NAC-Upper');

        $this->assertEquals('2026-09-06', $pass->activated_at->toDateString());
        $this->assertEquals('2027-09-05', $pass->expires_at->toDateString());

        Carbon::setTestNow();
    }
}
