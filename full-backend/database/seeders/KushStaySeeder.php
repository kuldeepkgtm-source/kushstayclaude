<?php

namespace Database\Seeders;

use App\Models\Bed;
use App\Models\CalendarSource;
use App\Models\PricingRule;
use App\Models\Property;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds exactly the prototype's fixed inventory (16 beds, 2 rooms, same bed codes) and default
 * prices, so `php artisan migrate --seed` reproduces the same starting point as the browser demo.
 */
class KushStaySeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'staff']);
        Role::firstOrCreate(['name' => 'viewer']);

        User::firstOrCreate(
            ['email' => 'admin@kushstay.example'],
            ['name' => 'Admin', 'password' => Hash::make(Str::random(20)), 'role_id' => $adminRole->id]
        );
        // No real password is printed here on purpose — use `php artisan tinker` or a password-reset
        // flow to set one. This is the direct replacement for the prototype's hardcoded admin/admin123.

        $property = Property::firstOrCreate(['name' => 'Kush Stay'], [
            'address' => 'Add your property address',
            'phone' => '+91 92385 82719',
            'whatsapp_number' => '+91 92385 82719',
            'email' => 'reservations@kushstay.example',
            'currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
        ]);

        $dormType = RoomType::firstOrCreate(['name' => 'Dormitory']);

        $acRoom = Room::firstOrCreate(
            ['property_id' => $property->id, 'code' => 'AC'],
            ['room_type_id' => $dormType->id, 'name' => 'AC Dormitory', 'is_ac' => true]
        );
        $nacRoom = Room::firstOrCreate(
            ['property_id' => $property->id, 'code' => 'NAC'],
            ['room_type_id' => $dormType->id, 'name' => 'Non-AC Dormitory', 'is_ac' => false]
        );

        foreach (['U1', 'U2', 'U3', 'U4'] as $n) {
            Bed::firstOrCreate(['code' => "AC-{$n}"], ['room_id' => $acRoom->id, 'position' => 'Upper']);
            Bed::firstOrCreate(['code' => "NAC-{$n}"], ['room_id' => $nacRoom->id, 'position' => 'Upper']);
        }
        foreach (['L1', 'L2', 'L3', 'L4'] as $n) {
            Bed::firstOrCreate(['code' => "AC-{$n}"], ['room_id' => $acRoom->id, 'position' => 'Lower']);
            Bed::firstOrCreate(['code' => "NAC-{$n}"], ['room_id' => $nacRoom->id, 'position' => 'Lower']);
        }

        // Base prices — identical values to the prototype's DEFAULT_PRICES
        $basePrices = ['AC-Upper' => 300, 'AC-Lower' => 350, 'NAC-Upper' => 200, 'NAC-Lower' => 250, 'AC-Private' => 2200, 'NAC-Private' => 1800];
        foreach ($basePrices as $bedType => $price) {
            PricingRule::firstOrCreate(
                ['property_id' => $property->id, 'bed_type' => $bedType, 'rule_type' => 'base'],
                ['price' => $price]
            );
            // Weekend surcharge — same 15% and same Fri(5)/Sat(6) nights as the prototype's default
            foreach ([5, 6] as $dow) {
                PricingRule::firstOrCreate(
                    ['property_id' => $property->id, 'bed_type' => $bedType, 'rule_type' => 'weekend', 'day_of_week' => $dow],
                    ['surcharge_pct' => 15]
                );
            }
        }

        foreach ([
            ['name' => 'Booking.com', 'room' => $acRoom, 'freq' => 30],
            ['name' => 'Airbnb', 'room' => $nacRoom, 'freq' => 60],
            ['name' => 'MakeMyTrip', 'room' => null, 'freq' => 120],
            ['name' => 'Goibibo', 'room' => null, 'freq' => 120],
        ] as $c) {
            CalendarSource::firstOrCreate(
                ['property_id' => $property->id, 'name' => $c['name']],
                ['type' => 'ota', 'room_id' => $c['room']?->id, 'export_token' => Str::random(40), 'sync_frequency_minutes' => $c['freq'], 'status' => 'Active']
            );
        }

        $this->command?->info('Seeded: 1 property, 2 rooms, 16 beds, base+weekend pricing, 4 OTA sources, admin role/user.');
    }
}
