<?php

namespace App\Http\Controllers\Api\Pass;

use App\Http\Controllers\Controller;
use App\Models\PassProduct;
use App\Models\PassSetting;
use App\Models\PassUpgradeRate;
use Illuminate\Http\Request;

class PassProductController extends Controller
{
    // GET /api/pass/products — prices are always resolved server-side (Grand Opening vs normal)
    public function index(Request $request)
    {
        $propertyId = $request->integer('property_id', 1);
        $settings = PassSetting::where('property_id', $propertyId)->firstOrFail();
        $products = PassProduct::where('property_id', $propertyId)->where('active', true)->get()->map(function ($p) use ($settings) {
            return [
                'id' => $p->id, 'category' => $p->category, 'display_name' => $p->display_name,
                'normal_price_paise' => $p->normal_price_paise,
                'current_price_paise' => $p->currentPricePaise($settings),
                'is_grand_opening_price' => $settings->grand_opening_active,
                'total_days' => $p->total_days,
            ];
        });

        return response()->json([
            'grand_opening_active' => $settings->grand_opening_active,
            'grand_opening_remaining' => max(0, $settings->grand_opening_limit - $settings->grand_opening_sold),
            'sold_out' => $settings->grand_opening_active && $settings->grand_opening_sold >= $settings->grand_opening_limit,
            'products' => $products,
        ]);
    }

    // GET /api/pass/upgrade-rates
    public function upgradeRates(Request $request)
    {
        return response()->json(PassUpgradeRate::where('property_id', $request->integer('property_id', 1))->where('active', true)->get());
    }
}
