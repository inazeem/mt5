<?php

namespace App\Http\Middleware;

use App\Models\AppSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureOwnerEmail
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ownerEmail = (string) env('APP_OWNER_EMAIL', '');

        if ($ownerEmail === '') {
            try {
                $ownerEmail = (string) (AppSetting::query()->value('owner_email') ?? '');
            } catch (Throwable $e) {
                $ownerEmail = '';
            }
        }

        if ($ownerEmail !== '' && $request->user() && strcasecmp($request->user()->email, $ownerEmail) !== 0) {
            abort(403, 'Only the configured owner account can access this application.');
        }

        return $next($request);
    }
}
