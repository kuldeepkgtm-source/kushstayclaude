<?php

namespace App\Console\Commands;

use App\Models\Bed;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Room;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan import:artifact-json {path} {--property=1} {--dry-run}
 *
 * Reads the JSON produced by the prototype's Demo & Tests -> "Export data (JSON)" button and
 * inserts real rows. Safe to re-run: existing booking_ref values are skipped (not duplicated),
 * and --dry-run reports what WOULD happen without writing anything.
 */
class ImportArtifactJson extends Command
{
    protected $signature = 'import:artifact-json {path} {--property=1} {--dry-run}';

    protected $description = 'Migrate a Kush Stay prototype JSON export into the MySQL database.';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! file_exists($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE || ! isset($data['bookings'])) {
            $this->error('That file does not look like a valid Kush Stay export (expected a "bookings" array).');

            return self::FAILURE;
        }

        $propertyId = (int) $this->option('property');
        $property = Property::find($propertyId);
        if (! $property) {
            $this->error("Property id {$propertyId} not found — seed the database first (php artisan db:seed).");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $bedsByCode = Bed::pluck('id', 'code');
        $roomsByCode = Room::where('property_id', $propertyId)->pluck('id', 'code');

        $imported = 0;
        $skippedDuplicate = 0;
        $skippedInvalid = 0;
        $report = [];

        DB::beginTransaction();
        try {
            foreach ($data['bookings'] as $b) {
                if (Booking::where('booking_ref', $b['bookingId'] ?? '')->exists()) {
                    $skippedDuplicate++;
                    $report[] = "SKIP (duplicate ref): {$b['bookingId']}";

                    continue;
                }

                $bedIds = collect($b['bedIds'] ?? [])->map(fn ($code) => $bedsByCode[$code] ?? null)->filter()->values();
                if ($bedIds->isEmpty() && ($b['bookingType'] ?? 'individual') === 'individual') {
                    $skippedInvalid++;
                    $report[] = "SKIP (no matching beds): {$b['bookingId']}";

                    continue;
                }

                $roomId = $roomsByCode[$b['roomKey'] ?? ''] ?? null;

                if (! $dryRun) {
                    $customer = null;
                    if (! empty($b['customerPhone'])) {
                        $customer = Customer::firstOrCreate(
                            ['phone' => $b['customerPhone']],
                            ['name' => $b['customerName'] ?? 'Guest']
                        );
                    }

                    $booking = Booking::create([
                        'booking_ref' => $b['bookingId'],
                        'property_id' => $propertyId,
                        'customer_id' => $customer?->id,
                        'customer_name' => $b['customerName'] ?? 'Guest',
                        'customer_phone' => $b['customerPhone'] ?? null,
                        'source' => $b['source'] ?? 'Manual/Admin',
                        'check_in' => $b['checkIn'],
                        'check_out' => $b['checkOut'],
                        'guest_count' => $b['guestCount'] ?? 1,
                        'booking_type' => $b['bookingType'] ?? 'individual',
                        'room_id' => $roomId,
                        'subtotal' => $b['subtotal'] ?? $b['total'] ?? 0,
                        'discount' => $b['discount'] ?? 0,
                        'tax' => $b['tax'] ?? 0,
                        'total' => $b['total'] ?? 0,
                        'amount_paid' => $b['amountPaid'] ?? 0,
                        'balance' => $b['balance'] ?? 0,
                        'payment_status' => $b['paymentStatus'] ?? 'Unpaid',
                        'payment_method' => $b['paymentMethod'] ?? null,
                        'booking_status' => $b['bookingStatus'] ?? 'Confirmed',
                        'external_booking_id' => $b['externalBookingId'] ?? null,
                        'special_request' => $b['specialRequest'] ?? null,
                        'created_at' => isset($b['createdAt']) ? date('Y-m-d H:i:s', (int) ($b['createdAt'] / 1000)) : now(),
                    ]);

                    $targetBedIds = ($b['bookingType'] ?? 'individual') === 'private' && $roomId
                        ? Bed::where('room_id', $roomId)->pluck('id')
                        : $bedIds;
                    $booking->beds()->attach($targetBedIds);
                }

                $imported++;
                $report[] = "OK: {$b['bookingId']}";
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Import failed, rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($report as $line) {
            $this->line($line);
        }
        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '')."Imported: {$imported}, duplicates skipped: {$skippedDuplicate}, invalid skipped: {$skippedInvalid}");

        return self::SUCCESS;
    }
}
