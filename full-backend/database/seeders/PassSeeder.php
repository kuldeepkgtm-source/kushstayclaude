<?php

namespace Database\Seeders;

use App\Models\PassProduct;
use App\Models\PassSetting;
use App\Models\PassUpgradeRate;
use App\Models\Property;
use Illuminate\Database\Seeder;

/** Seeds the exact prices and upgrade matrix from the spec. All money in integer paise. */
class PassSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::firstOrCreate(['name' => 'Kush Stay']);

        PassSetting::firstOrCreate(['property_id' => $property->id], [
            'grand_opening_active' => true, 'grand_opening_limit' => 150, 'grand_opening_sold' => 0,
        ]);

        $products = [
            ['category' => 'NAC-Upper', 'display_name' => 'Upper Non-AC', 'normal' => 550000, 'grand' => 450000],
            ['category' => 'NAC-Lower', 'display_name' => 'Lower Non-AC', 'normal' => 650000, 'grand' => 550000],
            ['category' => 'AC-Upper', 'display_name' => 'Upper AC', 'normal' => 650000, 'grand' => 550000],
            ['category' => 'AC-Lower', 'display_name' => 'Lower AC', 'normal' => 750000, 'grand' => 650000],
        ];
        foreach ($products as $p) {
            PassProduct::firstOrCreate(
                ['property_id' => $property->id, 'category' => $p['category']],
                ['display_name' => $p['display_name'], 'normal_price_paise' => $p['normal'], 'grand_opening_price_paise' => $p['grand'], 'total_days' => 30]
            );
        }

        // Exact upgrade matrix from the spec, in paise (₹40 -> 4000, ₹80 -> 8000).
        $matrix = [
            'NAC-Upper' => ['NAC-Upper' => 0, 'NAC-Lower' => 4000, 'AC-Upper' => 4000, 'AC-Lower' => 8000],
            'NAC-Lower' => ['NAC-Upper' => 0, 'NAC-Lower' => 0, 'AC-Upper' => 4000, 'AC-Lower' => 8000],
            'AC-Upper' => ['NAC-Upper' => 0, 'NAC-Lower' => 0, 'AC-Upper' => 0, 'AC-Lower' => 4000],
            'AC-Lower' => ['NAC-Upper' => 0, 'NAC-Lower' => 0, 'AC-Upper' => 0, 'AC-Lower' => 0],
        ];
        foreach ($matrix as $base => $targets) {
            foreach ($targets as $target => $feePaise) {
                PassUpgradeRate::firstOrCreate(
                    ['property_id' => $property->id, 'base_category' => $base, 'target_category' => $target],
                    ['fee_per_night_paise' => $feePaise]
                );
            }
        }

        $this->command?->info('Seeded: pass_settings (150 Grand Opening cap), 4 pass products, 16 upgrade-rate rows.');
    }
}
