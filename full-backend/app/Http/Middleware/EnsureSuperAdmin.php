<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user() && $request->user()->is_super_admin, 403, 'Kush Stay Super Admin access required.');

        return $next($request);
    }
}
