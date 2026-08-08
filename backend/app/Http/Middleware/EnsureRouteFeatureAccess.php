<?php

namespace App\Http\Middleware;

use App\Domain\AccessControl\Services\EffectiveAccessResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureRouteFeatureAccess
{
    public function __construct(private EffectiveAccessResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $path = preg_replace('#^api/#', '', $request->path());
        $feature = $this->featureFor($path);
        if (! $feature || ! DB::table('features')->where('code', $feature)->exists()) {
            return $next($request);
        } abort_unless($request->user() && $this->resolver->hasFeature($request->user(), $feature), 403);

        return $next($request);
    }

    private function featureFor(string $path): ?string
    {
        return match (true) {
            str_starts_with($path, 'admin/work-'),str_starts_with($path, 'admin/shifts'),str_starts_with($path, 'admin/attendance') => 'attendance',str_starts_with($path, 'admin/paid-leave'),str_starts_with($path, 'admin/special-leave') => 'paid_leave',str_starts_with($path, 'admin/request-types') => 'workflow',str_starts_with($path, 'admin/expense-categories') => 'backoffice',str_starts_with($path, 'attendance'),str_starts_with($path, 'work-calendars'),str_starts_with($path, 'work-styles'),str_starts_with($path, 'shift-patterns'),str_starts_with($path, 'rotation-patterns'),str_starts_with($path, 'employee-shift'),str_starts_with($path, 'employee-rotation'),str_starts_with($path, 'user-work-style'),str_starts_with($path, 'employment-categories'),str_starts_with($path, 'exports/attendance') => 'attendance',str_starts_with($path, 'paid-leave'),str_starts_with($path, 'special-leave'),str_starts_with($path, 'compensatory-leave') => 'paid_leave',str_starts_with($path, 'workflow-requests'),str_starts_with($path, 'shift-swap') => 'workflow',str_starts_with($path, 'expense-claims'),str_starts_with($path, 'expense-entry-presets'),str_starts_with($path, 'expense-categories'),str_starts_with($path, 'backoffice-tasks'),str_starts_with($path, 'exports/expenses') => 'backoffice',str_starts_with($path, 'admin'),str_starts_with($path, 'users') => 'administration',default => null
        };
    }
}
