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
        if (! $feature) {
            return $next($request);
        }
        // Most legacy feature tests exercise domain behaviour without seeding the
        // product access catalogue. Dedicated access-control integration tests turn
        // this compatibility switch off and verify the real feature boundary.
        if (config('access_control.allow_unconfigured_catalog', false)) {
            return $next($request);
        }
        abort_unless(DB::table('features')->where('code', $feature)->exists(), 500, "Undefined feature: {$feature}");
        abort_unless($request->user() && $this->resolver->hasFeature($request->user(), $feature), 403);

        return $next($request);
    }

    private function featureFor(string $path): ?string
    {
        return match (true) {
            $path === 'users/search' => null,
            str_starts_with($path, 'attendance/clock-'), str_starts_with($path, 'attendance/break/') => 'attendance.clock',
            str_starts_with($path, 'attendance/month'), str_starts_with($path, 'exports/attendance') => 'attendance.timesheet',
            str_starts_with($path, 'attendance'), str_starts_with($path, 'work-calendars'), str_starts_with($path, 'work-styles'), str_starts_with($path, 'shift-patterns'), str_starts_with($path, 'rotation-patterns'), str_starts_with($path, 'employee-shift'), str_starts_with($path, 'employee-rotation'), str_starts_with($path, 'user-work-style'), str_starts_with($path, 'employment-categories'), str_starts_with($path, 'admin/work-'), str_starts_with($path, 'admin/shifts'), str_starts_with($path, 'admin/attendance') => 'attendance.entry',
            str_starts_with($path, 'paid-leave'), str_starts_with($path, 'special-leave'), str_starts_with($path, 'compensatory-leave'), str_starts_with($path, 'admin/paid-leave'), str_starts_with($path, 'admin/special-leave') => 'paid_leave.requests',
            str_starts_with($path, 'workflow-requests'), str_starts_with($path, 'shift-swap'), str_starts_with($path, 'admin/request-types') => 'workflow.requests',
            str_starts_with($path, 'backoffice-tasks') => 'backoffice.tasks',
            str_starts_with($path, 'expense-claims'), str_starts_with($path, 'expense-entry-presets'), str_starts_with($path, 'expense-categories'), str_starts_with($path, 'exports/expenses'), str_starts_with($path, 'admin/expense-categories') => 'backoffice.expenses',
            str_starts_with($path, 'admin/user-management'), str_starts_with($path, 'admin/access-control'), str_starts_with($path, 'users') => 'administration.users',
            str_starts_with($path, 'admin') => 'administration.settings',
            default => null,
        };
    }
}
