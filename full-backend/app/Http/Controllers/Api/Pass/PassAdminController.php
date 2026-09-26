<?php

namespace App\Http\Controllers\Api\Pass;

use App\Http\Controllers\Controller;
use App\Models\Pass;
use App\Models\PassAuditLog;
use App\Models\PassBooking;
use App\Models\PassSetting;
use App\Services\PassLedgerService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SECURITY: every method authorizes against the pass's own property_id via TenantContext,
 * independent of whatever route/middleware points here. A super admin passes automatically;
 * anyone else needs an explicit property_users membership on that specific pass's property.
 * This was previously missing entirely — see the audit note in routes/api.php's git history —
 * and must not be re-removed even if a route is later added for a narrower role.
 */
class PassAdminController extends Controller
{
    public function __construct(private PassLedgerService $ledger, private TenantContext $tenant) {}

    // GET /api/admin/passes?search=&property_id=
    public function index(Request $request)
    {
        $ids = $this->tenant->accessiblePropertyIds($request->user());
        if ($request->filled('property_id')) {
            abort_unless(in_array((int) $request->integer('property_id'), $ids, true), 403);
            $ids = [(int) $request->integer('property_id')];
        }

        $query = Pass::with('customer', 'product')->whereIn('property_id', $ids);
        if ($request->filled('search')) {
            $s = $request->string('search');
            $query->where(fn ($q) => $q->where('pass_ref', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($q2) => $q2->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")));
        }

        return response()->json($query->latest()->paginate(50));
    }

    // GET /api/admin/passes/stats?property_id= — property_id is now checked, not just trusted
    public function stats(Request $request)
    {
        $propertyId = $request->integer('property_id', 1);
        abort_unless($this->tenant->hasAccess($request->user(), $propertyId), 403, 'You do not have access to this property.');

        $settings = PassSetting::where('property_id', $propertyId)->first();
        $byStatus = Pass::where('property_id', $propertyId)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return response()->json([
            'total_passes' => Pass::where('property_id', $propertyId)->count(),
            'grand_opening_sold' => $settings->grand_opening_sold ?? 0,
            'grand_opening_limit' => $settings->grand_opening_limit ?? 150,
            'by_status' => $byStatus,
            'revenue_paise' => Pass::where('property_id', $propertyId)->whereIn('status', ['active', 'expired', 'suspended', 'cancelled'])->sum('price_paid_paise'),
            'pass_days_issued' => Pass::where('property_id', $propertyId)->whereNotIn('status', ['pending', 'payment_pending', 'refunded'])->sum('total_days'),
            'pass_days_consumed' => Pass::where('property_id', $propertyId)->sum('used_days'),
            'pass_days_remaining' => Pass::where('property_id', $propertyId)->sum('remaining_days'),
            'upgrade_revenue_paise' => PassBooking::whereHas('pass', fn ($q) => $q->where('property_id', $propertyId))->where('upgrade_payment_status', 'paid')->sum('upgrade_fee_paise'),
            'pass_bookings' => PassBooking::whereHas('pass', fn ($q) => $q->where('property_id', $propertyId))->count(),
        ]);
    }

    // GET /api/admin/passes/{pass}
    public function show(Request $request, Pass $pass)
    {
        $this->assertOwnership($request, $pass);

        return response()->json($pass->load('customer', 'product', 'ledger', 'bookings.booking', 'auditLogs'));
    }

    // GET /api/admin/passes/{pass}/ledger
    public function ledger(Request $request, Pass $pass)
    {
        $this->assertOwnership($request, $pass);

        return response()->json($pass->ledger);
    }

    public function suspend(Request $request, Pass $pass)
    {
        $this->assertOwnership($request, $pass);

        return $this->transition($request, $pass, 'suspended', 'suspend');
    }

    public function reactivate(Request $request, Pass $pass)
    {
        $this->assertOwnership($request, $pass);

        return $this->transition($request, $pass, 'active', 'reactivate');
    }

    public function cancel(Request $request, Pass $pass)
    {
        $this->assertOwnership($request, $pass);

        return $this->transition($request, $pass, 'cancelled', 'cancel');
    }

    // POST /api/admin/passes/{pass}/extend — authorized expiry extension
    public function extend(Request $request, Pass $pass)
    {
        $this->assertOwnership($request, $pass);
        $data = $request->validate(['new_expires_at' => 'required|date_format:Y-m-d|after:today', 'reason' => 'required|string']);
        $before = $pass->only('expires_at');
        $pass->update(['expires_at' => $data['new_expires_at']]);
        PassAuditLog::create(['pass_id' => $pass->id, 'admin_user_id' => $request->user()->id, 'action' => 'extend_expiry', 'before' => $before, 'after' => $pass->only('expires_at'), 'reason' => $data['reason']]);

        return response()->json($pass->fresh());
    }

    // POST /api/admin/passes/{pass}/adjust — audited manual balance correction; never silent
    public function adjust(Request $request, Pass $pass)
    {
        $this->assertOwnership($request, $pass);
        $data = $request->validate(['day_change' => 'required|integer', 'reason' => 'required|string|min:5']);

        return DB::transaction(function () use ($request, $pass, $data) {
            $locked = Pass::lockForUpdate()->findOrFail($pass->id);
            $before = $locked->only('remaining_days', 'used_days');

            $this->ledger->record($locked, 'adjustment', $data['day_change'], null, $data['reason'], $request->user()->id);

            $after = $locked->fresh()->only('remaining_days', 'used_days');
            PassAuditLog::create(['pass_id' => $locked->id, 'admin_user_id' => $request->user()->id, 'action' => 'adjust_balance', 'before' => $before, 'after' => $after, 'reason' => $data['reason']]);

            return response()->json($locked->fresh());
        });
    }

    private function transition(Request $request, Pass $pass, string $newStatus, string $action)
    {
        $data = $request->validate(['reason' => 'required|string|min:5']);
        $before = $pass->only('status');
        $pass->update(['status' => $newStatus]);
        PassAuditLog::create(['pass_id' => $pass->id, 'admin_user_id' => $request->user()->id, 'action' => $action, 'before' => $before, 'after' => $pass->only('status'), 'reason' => $data['reason']]);

        return response()->json($pass->fresh());
    }

    /** Independent of route middleware on purpose — see class docblock. */
    private function assertOwnership(Request $request, Pass $pass): void
    {
        abort_unless($this->tenant->hasAccess($request->user(), $pass->property_id), 403, 'You do not have access to this property.');
    }
}
