<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;

class CookieTokenToBearer
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->bearerToken() && $request->cookie('fornecefy_token')) {
            $request->headers->set('Authorization', 'Bearer ' . $request->cookie('fornecefy_token'));
        }
        return $next($request);
    }
}
