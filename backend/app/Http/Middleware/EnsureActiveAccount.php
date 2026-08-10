<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $principal = $request->user();
        if ($principal instanceof User) {
            $accountStatus = $principal->account_status ?? $principal->newQuery()->whereKey($principal->getKey())->value('account_status');
            abort_unless(in_array($accountStatus, ['active', 'leave'], true), 403, 'このアカウントは現在利用できません。');
        }

        return $next($request);
    }
}
