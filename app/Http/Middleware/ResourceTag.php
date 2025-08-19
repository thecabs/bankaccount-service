<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ResourceTag
{
    public function handle(Request $req, Closure $next, string $sensitivity = 'LOW')
    {
        $req->attributes->set('zt.resource.sensitivity', strtoupper($sensitivity));
        return $next($req);
    }
}
