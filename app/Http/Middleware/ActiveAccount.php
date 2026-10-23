<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->is('logout', 'api/v1/auth/logout')) {
            abort_if($request->user() && $request->user()->status !== 'active', 403, 'Tài khoản đã bị khóa.');
        }

        return $next($request);
    }
}
