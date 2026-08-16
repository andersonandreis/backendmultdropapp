<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscription
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user && $user->role === 'client') {
            $subscription = $user->client->subscriptions()->where('status', 'active')->first();

            if (!$subscription) {
                // If not active or in trial, redirect to checkout
                return redirect()->route('subscription.checkout');
            }
        }

        return $next($request);
    }
}
