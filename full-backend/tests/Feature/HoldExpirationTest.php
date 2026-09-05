<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Services\AvailabilityService;
use App\Services\HoldService;

/** Test 8 (hold expiration), server-timed per the request's explicit requirement. */
class HoldExpirationTest extends TestCase
{
    public function test_hold_blocks_the_bed_then_releases_after_expiry(): void
    {
        $holds = app(HoldService::class);
        $availability = app(AvailabilityService::class);
        $bed = $this->bed('AC-U2');

        $holds->createHold($this->property->id, [$bed->id], '2027-01-01', '2027-01-02', 'session-abc', 10);
        $this->assertFalse($availability->isBedFree($bed->id, '2027-01-01', '2027-01-02'));

        // Force the hold's expiry into the past directly in the DB (simulating server-clock elapse —
        // note this is DB time, not a browser clock, which is exactly the point being tested).
        Hold::where('session_token', 'session-abc')->update(['expires_at' => now()->subMinute()]);

        $this->assertTrue($availability->isBedFree($bed->id, '2027-01-01', '2027-01-02'), 'expired hold must not block availability');

        $purged = $holds->purgeExpired();
        $this->assertGreaterThanOrEqual(1, $purged);
        $this->assertDatabaseMissing('holds', ['session_token' => 'session-abc']);
    }

    public function test_a_second_hold_on_the_same_bed_is_rejected_while_active(): void
    {
        $holds = app(HoldService::class);
        $bed = $this->bed('AC-U3');
        $holds->createHold($this->property->id, [$bed->id], '2027-02-01', '2027-02-02', 'session-1');

        $this->expectException(\App\Exceptions\BookingConflictException::class);
        $holds->createHold($this->property->id, [$bed->id], '2027-02-01', '2027-02-02', 'session-2');
    }
}
