<?php

namespace Tests\Feature;

use App\Models\CalendarSource;
use App\Services\BookingService;
use App\Services\IcalService;

/** Tests 11, 12, 13: iCal import (with UID dedup), update, and cancellation reconciliation. */
class IcalTest extends TestCase
{
    private function sampleIcs(string $uid, string $checkIn, string $checkOut, string $summary = 'External Guest'): string
    {
        $fmt = fn ($d) => str_replace('-', '', $d);

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:{$uid}\r\n".
            "DTSTART;VALUE=DATE:{$fmt($checkIn)}\r\nDTEND;VALUE=DATE:{$fmt($checkOut)}\r\n".
            "SUMMARY:{$summary}\r\nEND:VEVENT\r\nEND:VCALENDAR";
    }

    public function test_import_creates_a_real_booking_from_a_parsed_event(): void
    {
        $ical = app(IcalService::class);
        $source = CalendarSource::create([
            'property_id' => $this->property->id, 'name' => 'Test OTA', 'type' => 'ical',
            'room_id' => $this->nacRoom->id, 'export_token' => 'tok1', 'status' => 'Active',
        ]);

        $events = $ical->parse($this->sampleIcs('EXT-UID-1', '2026-09-20', '2026-09-22'));
        $this->assertCount(1, $events);

        $report = $ical->importEvents($source, $events);
        $this->assertEquals(1, $report['created']);
        $this->assertDatabaseHas('bookings', ['external_booking_id' => 'EXT-UID-1', 'source' => 'iCal Import']);
    }

    public function test_reimporting_the_same_uid_updates_instead_of_duplicating(): void
    {
        $ical = app(IcalService::class);
        $source = CalendarSource::create([
            'property_id' => $this->property->id, 'name' => 'Test OTA 2', 'type' => 'ical',
            'room_id' => $this->nacRoom->id, 'export_token' => 'tok2', 'status' => 'Active',
        ]);

        $events = $ical->parse($this->sampleIcs('EXT-UID-2', '2026-09-25', '2026-09-27'));
        $ical->importEvents($source, $events); // first import: created
        $report2 = $ical->importEvents($source, $events); // second import, same UID: must update, not duplicate

        $this->assertEquals(0, $report2['created']);
        $this->assertEquals(1, $report2['updated']);
        $this->assertEquals(1, \App\Models\CalendarEvent::where('external_uid', 'EXT-UID-2')->count());
        $this->assertEquals(1, \App\Models\Booking::where('external_booking_id', 'EXT-UID-2')->count());
    }

    public function test_event_missing_from_a_later_feed_is_reconciled_as_cancelled(): void
    {
        $ical = app(IcalService::class);
        $source = CalendarSource::create([
            'property_id' => $this->property->id, 'name' => 'Test OTA 3', 'type' => 'ical',
            'room_id' => $this->acRoom->id, 'export_token' => 'tok3', 'status' => 'Active',
        ]);

        $events = $ical->parse($this->sampleIcs('EXT-UID-3', '2026-10-05', '2026-10-07'));
        $ical->importEvents($source, $events);

        // Next sync's feed no longer contains EXT-UID-3 -> reconcile as cancelled
        $stale = $ical->reconcileCancellations($source, []);
        $this->assertEquals(1, $stale);
        $this->assertDatabaseHas('calendar_events', ['external_uid' => 'EXT-UID-3', 'status' => 'cancelled']);
        $this->assertDatabaseHas('bookings', ['external_booking_id' => 'EXT-UID-3', 'booking_status' => 'Cancelled']);
    }

    public function test_generated_ics_round_trips_through_the_parser(): void
    {
        $bookings = app(BookingService::class);
        $ical = app(IcalService::class);
        $booking = $bookings->createBooking([
            'property_id' => $this->property->id, 'customer_name' => 'Round Trip Guest', 'customer_phone' => '9876500050',
            'source' => 'Direct', 'check_in' => '2026-11-01', 'check_out' => '2026-11-03',
            'guest_count' => 1, 'booking_type' => 'individual', 'room_id' => $this->acRoom->id, 'bed_ids' => [$this->bed('AC-U4')->id],
        ]);

        $ics = $ical->generate([$booking->load('beds')], 'Test Calendar');
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString($booking->booking_ref, $ics);

        $parsed = $ical->parse($ics);
        $this->assertEquals('2026-11-01', $parsed[0]['check_in']);
        $this->assertEquals('2026-11-03', $parsed[0]['check_out']);
    }
}
