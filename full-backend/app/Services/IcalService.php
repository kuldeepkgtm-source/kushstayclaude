<?php

namespace App\Services;

use App\Exceptions\BookingConflictException;
use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\CalendarSource;
use Illuminate\Support\Str;

/**
 * PHP port of the prototype's generateICS()/parseICS(). Generation is unchanged in spirit;
 * import now dedupes by (calendar_source_id, external_uid) via calendar_events' unique index,
 * so re-running the same sync (or re-uploading the same .ics) never creates a duplicate booking —
 * a gap the browser prototype had.
 */
class IcalService
{
    public function __construct(
        private AvailabilityService $availability,
        private BookingService $bookings,
    ) {}

    public function generate(iterable $bookings, string $calendarName): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Kush Stay//Booking System//EN',
            'CALSCALE:GREGORIAN', 'X-WR-CALNAME:'.$this->escape($calendarName)];

        foreach ($bookings as $booking) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$booking->booking_ref.'@kushstay';
            $lines[] = 'DTSTAMP:'.gmdate('Ymd\THis\Z', strtotime($booking->created_at));
            $lines[] = 'DTSTART;VALUE=DATE:'.str_replace('-', '', $booking->check_in->toDateString());
            $lines[] = 'DTEND;VALUE=DATE:'.str_replace('-', '', $booking->check_out->toDateString());
            $lines[] = 'SUMMARY:'.$this->escape($booking->customer_name.' — '.$booking->beds->pluck('code')->implode(', '));
            $lines[] = 'STATUS:'.($booking->booking_status === 'Cancelled' ? 'CANCELLED' : 'CONFIRMED');
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines);
    }

    /** @return array{uid:string, check_in:string, check_out:string, summary:string}[] */
    public function parse(string $icsText): array
    {
        $events = [];
        $blocks = preg_split('/BEGIN:VEVENT/i', $icsText);
        array_shift($blocks); // text before the first VEVENT

        foreach ($blocks as $block) {
            $body = preg_split('/END:VEVENT/i', $block)[0];
            $get = function (string $key) use ($body) {
                if (preg_match('/'.$key.'[^:]*:([^\r\n]+)/', $body, $m)) {
                    return trim($m[1]);
                }

                return null;
            };

            $uid = $get('UID') ?? (string) Str::uuid();
            $start = $get('DTSTART');
            $end = $get('DTEND');
            $toIso = function (?string $raw) {
                if (! $raw) {
                    return null;
                }
                $digits = substr(preg_replace('/[^0-9]/', '', $raw), 0, 8);

                return strlen($digits) < 8 ? null : substr($digits, 0, 4).'-'.substr($digits, 4, 2).'-'.substr($digits, 6, 2);
            };

            $checkIn = $toIso($start);
            $checkOut = $toIso($end);
            if ($checkIn && $checkOut) {
                $events[] = ['uid' => $uid, 'check_in' => $checkIn, 'check_out' => $checkOut, 'summary' => $get('SUMMARY') ?? 'Imported event'];
            }
        }

        return $events;
    }

    /**
     * Import parsed events for one calendar source. Each event maps to the source's configured
     * bed/room; a previously-seen UID only updates last_seen_at (and dates, if changed) instead
     * of creating a second booking.
     *
     * @return array{created:int, updated:int, skipped:int}
     */
    public function importEvents(CalendarSource $source, array $events): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($events as $event) {
            $existing = CalendarEvent::where('calendar_source_id', $source->id)
                ->where('external_uid', $event['uid'])->first();

            if ($existing) {
                $existing->update(['last_seen_at' => now(), 'check_in' => $event['check_in'], 'check_out' => $event['check_out']]);
                $updated++;

                continue;
            }

            $bedId = $source->bed_id ?? $this->pickBedForRoom($source->room_id, $event['check_in'], $event['check_out']);
            if (! $bedId) {
                // No free bed to map this event to — record it for visibility, but no booking.
                CalendarEvent::create([
                    'calendar_source_id' => $source->id,
                    'external_uid' => $event['uid'],
                    'check_in' => $event['check_in'],
                    'check_out' => $event['check_out'],
                    'summary' => $event['summary'],
                    'status' => 'active',
                    'last_seen_at' => now(),
                ]);
                $skipped++;

                continue;
            }

            try {
                $booking = $this->bookings->createBooking([
                    'property_id' => $source->property_id,
                    'customer_name' => $event['summary'],
                    'customer_phone' => null,
                    'source' => 'iCal Import',
                    'check_in' => $event['check_in'],
                    'check_out' => $event['check_out'],
                    'guest_count' => 1,
                    'booking_type' => 'individual',
                    'room_id' => $source->room_id ?? \App\Models\Bed::find($bedId)->room_id,
                    'bed_ids' => [$bedId],
                    'external_booking_id' => $event['uid'],
                ]);

                CalendarEvent::create([
                    'calendar_source_id' => $source->id,
                    'external_uid' => $event['uid'],
                    'check_in' => $event['check_in'],
                    'check_out' => $event['check_out'],
                    'summary' => $event['summary'],
                    'status' => 'active',
                    'booking_id' => $booking->id,
                    'last_seen_at' => now(),
                ]);
                $created++;
            } catch (BookingConflictException) {
                $skipped++;
            }
        }

        $source->update(['last_synced_at' => now()]);

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    /** Mark previously-imported events as cancelled if they've disappeared from the latest feed. */
    public function reconcileCancellations(CalendarSource $source, array $latestUids): int
    {
        $stale = CalendarEvent::where('calendar_source_id', $source->id)
            ->where('status', 'active')->whereNotIn('external_uid', $latestUids)->get();

        foreach ($stale as $event) {
            $event->update(['status' => 'cancelled']);
            if ($event->booking_id) {
                $event->booking->update(['booking_status' => 'Cancelled']);
            }
        }

        return $stale->count();
    }

    private function pickBedForRoom(?int $roomId, string $checkIn, string $checkOut): ?int
    {
        if (! $roomId) {
            return null;
        }
        $free = $this->availability->availableBeds($roomId, $checkIn, $checkOut);

        return $free->first()?->id;
    }

    private function escape(string $s): string
    {
        return str_replace(["\\", ',', ';', "\n"], ['\\\\', '\\,', '\\;', '\\n'], $s);
    }
}
