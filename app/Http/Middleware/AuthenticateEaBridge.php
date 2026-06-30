<?php

namespace App\Http\Middleware;

use App\Services\EaBridgeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateEaBridge
{
    public function __construct(private readonly EaBridgeService $eaBridge) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');
        $token = $header;

        if (str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);
        }

        if (! $this->eaBridge->tokenIsValid($token)) {
            return response()->json([
                'ok' => false,
                'error' => 'Unauthorized',
            ], 401);
        }

        return $next($request);
    }
}
