<?php
// app/Http/Middleware/DashboardAuth.php
// Replaces repeated `if (!session('dashboard_logged_in'))` in every controller.
// Register in bootstrap/app.php:
//
// ->withMiddleware(function (Middleware $m) {
//   $m->alias(['dashboard.auth' => \App\Http\Middleware\DashboardAuth::class]);
// })

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DashboardAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! session('dashboard_logged_in')) {
            // API wants JSON, browser wants redirect
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }
            return redirect('/login');
        }
        return $next($request);
    }
}
