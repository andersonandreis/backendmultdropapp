<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckInstalled
{
    /**
     * Handle an incoming request.
     * Check if install file exists, otherwise redirect to Installer.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!file_exists(storage_path('installed')) && !$request->is('install*')) {
            return redirect()->route('installer.index');
        }

        return $next($request);
    }
}
