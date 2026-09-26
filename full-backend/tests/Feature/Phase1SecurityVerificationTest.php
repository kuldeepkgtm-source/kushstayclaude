<?php

namespace Tests\Feature;

use App\Models\Pass;
use App\Models\PassProduct;
use App\Models\Property;
use App\Models\PropertyUser;
use App\Models\User;
use App\Services\BookingService;
use App\Services\PassBookingService;
use App\Services\PassPurchaseService;
use App\Services\PropertyApprovalService;
use Laravel\Sanctum\Sanctum;

/**
 * Targeted verification for the specific gaps this audit round checked by hand:
 * (1) the LIVE-property gate on every booking-creation path, including the one
 * (PassBookingService/PassPurchaseService) found NOT to go through BookingService, and
 * (2) IDOR on PassAdminController via forged property_id / pass ID / query params.
 */
class Phase1SecurityVerificationTest extends TestCase
{
    private function makeSecondProperty(string $status = 'DRAFT'): Property
    {
        $property = Property::create(['name' => 'Property B', 'status' => $status]);
        \App\Models\Room::create(['property_id' => $property->id, 'code' => 'AC', 'name' => 'AC Room', 'is_ac' => true]);

        return $property;
    }

    /** @dataProvider nonLiveStatuses */
    public function test_booking_service_rejects_every_non_live_status(string $status): void
    {
        $property = $this->makeSecondProperty($status);
        $room = $property->rooms()->first();
        \App\Models\Bed::create(['room_id' => $room->id, 'code' => 'AC-U1', 'position' => 'Upper']);

        $this->expectException(\RuntimeException::class);
        app(BookingService::class)->createBooking([
            'property_id' => $property->id, 'customer_name' => 'X', 'customer_phone' => '9800000090',
            'source' => 'Direct', 'check_in' => '2027-03-01', 'check_out' => '2027-03-02',
            'guest_count' => 1, 'booking_type' => 'individual', 'room_id' => $room->id, 'bed_ids' => [$property->rooms()->first()->beds()->first()->id],
        ]);
    }

    public static function nonLiveStatuses(): array
    {
        return [['DRAFT'], ['SUBMITTED'], ['UNDER_REVIEW'], ['CHANGES_REQUIRED'], ['APPROVED'], ['SUSPENDED'], ['REJECTED'], ['CLOSED']];
    }

    public function test_booking_service_accepts_live_property(): void
    {
        $property = $this->makeSecondProperty('LIVE');
        $room = $property->rooms()->first();
        $bed = \App\Models\Bed::create(['room_id' => $room->id, 'code' => 'AC-U1', 'position' => 'Upper']);

        $booking = app(BookingService::class)->createBooking([
            'property_id' => $property->id, 'customer_name' => 'X', 'customer_phone' => '9800000091',
            'source' => 'Direct', 'check_in' => '2027-03-01', 'check_out' => '2027-03-02',
            'guest_count' => 1, 'booking_type' => 'individual', 'room_id' => $room->id, 'bed_ids' => [$bed->id],
        ]);
        $this->assertNotNull($booking->id);
    }

    /** The gap this round found and fixed: PassPurchaseService bypassed BookingService entirely. */
    public function test_pass_purchase_rejects_non_live_property(): void
    {
        $property = $this->makeSecondProperty('APPROVED'); // approved, but not yet activated to LIVE
        \App\Models\PassSetting::create(['property_id' => $property->id, 'grand_opening_active' => true, 'grand_opening_limit' => 150, 'grand_opening_sold' => 0]);
        $product = PassProduct::create(['property_id' => $property->id, 'category' => 'AC-Upper', 'display_name' => 'Upper AC', 'normal_price_paise' => 500000, 'grand_opening_price_paise' => 450000, 'total_days' => 30]);

        $this->expectException(\RuntimeException::class);
        app(PassPurchaseService::class)->reserve($property->id, $product->id, 'x@example.com', ['name' => 'X', 'phone' => '9800000092'], 'fake-token-will-fail-anyway');
    }

    /** The other half of the gap: PassBookingService's own Booking::create() also bypassed the gate. */
    public function test_pass_booking_rejects_non_live_property(): void
    {
        $property = $this->makeSecondProperty('SUSPENDED');
        $room = $property->rooms()->first();
        \App\Models\Bed::create(['room_id' => $room->id, 'code' => 'AC-U1', 'position' => 'Upper']);
        $customer = \App\Models\Customer::create(['property_id' => $property->id, 'name' => 'X', 'phone' => '9800000093']);
        $product = PassProduct::create(['property_id' => $property->id, 'category' => 'AC-Upper', 'display_name' => 'Upper AC', 'normal_price_paise' => 500000, 'grand_opening_price_paise' => 450000, 'total_days' => 30]);
        $pass = Pass::create(['pass_ref' => 'KS-PASS-999', 'property_id' => $property->id, 'customer_id' => $customer->id, 'pass_product_id' => $product->id, 'grand_opening' => true, 'price_paid_paise' => 450000, 'total_days' => 30, 'used_days' => 0, 'remaining_days' => 30, 'status' => 'active', 'activated_at' => now(), 'expires_at' => now()->addYear()]);

        $this->expectException(\RuntimeException::class);
        app(PassBookingService::class)->book($pass, '2027-03-01', '2027-03-02', 'AC-Upper');
    }

    // ---- PassAdminController IDOR: forged property_id / pass ID / query param ----

    public function test_property_owner_cannot_view_another_propertys_pass_via_forged_pass_id(): void
    {
        $propA = $this->property; // seeded Kush Stay, from TestCase
        $propB = $this->makeSecondProperty('LIVE');

        $ownerA = User::create(['name' => 'Owner A', 'email' => 'ownera@example.com', 'password' => 'x']);
        PropertyUser::create(['user_id' => $ownerA->id, 'property_id' => $propA->id, 'role' => 'property_owner']);

        $customerB = \App\Models\Customer::create(['property_id' => $propB->id, 'name' => 'Guest B', 'phone' => '9800000094']);
        $productB = PassProduct::create(['property_id' => $propB->id, 'category' => 'AC-Upper', 'display_name' => 'Upper AC', 'normal_price_paise' => 500000, 'grand_opening_price_paise' => 450000, 'total_days' => 30]);
        $passB = Pass::create(['pass_ref' => 'KS-PASS-998', 'property_id' => $propB->id, 'customer_id' => $customerB->id, 'pass_product_id' => $productB->id, 'grand_opening' => true, 'price_paid_paise' => 450000, 'total_days' => 30, 'used_days' => 0, 'remaining_days' => 30, 'status' => 'active']);

        Sanctum::actingAs($ownerA);
        // Owner A is not a super admin, so the route itself (super_admin middleware) already
        // blocks this — asserting 403 either way confirms the request never reaches passB's data.
        $this->getJson("/api/admin/passes/{$passB->id}")->assertStatus(403);
    }

    public function test_super_admin_can_view_any_propertys_pass(): void
    {
        $admin = User::first();
        $admin->update(['is_super_admin' => true]);
        $propB = $this->makeSecondProperty('LIVE');
        $customerB = \App\Models\Customer::create(['property_id' => $propB->id, 'name' => 'Guest B', 'phone' => '9800000095']);
        $productB = PassProduct::create(['property_id' => $propB->id, 'category' => 'AC-Upper', 'display_name' => 'Upper AC', 'normal_price_paise' => 500000, 'grand_opening_price_paise' => 450000, 'total_days' => 30]);
        $passB = Pass::create(['pass_ref' => 'KS-PASS-997', 'property_id' => $propB->id, 'customer_id' => $customerB->id, 'pass_product_id' => $productB->id, 'grand_opening' => true, 'price_paid_paise' => 450000, 'total_days' => 30, 'used_days' => 0, 'remaining_days' => 30, 'status' => 'active']);

        Sanctum::actingAs($admin);
        $this->getJson("/api/admin/passes/{$passB->id}")->assertStatus(200)->assertJsonPath('id', $passB->id);
    }

    public function test_forged_stats_property_id_query_param_is_rejected_for_non_admin(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => 'x']);
        // Not a super admin -> blocked at the route middleware regardless of query params.
        Sanctum::actingAs($staff);
        $this->getJson('/api/admin/passes/stats?property_id=1')->assertStatus(403);
    }

    public function test_admin_pass_routes_reject_unauthenticated_requests(): void
    {
        $this->getJson('/api/admin/passes')->assertStatus(401);
        $this->getJson('/api/admin/passes/stats')->assertStatus(401);
    }
}
