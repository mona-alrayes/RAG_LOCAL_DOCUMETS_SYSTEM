<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminAccess;
use App\Services\Admin\AdminAudit;
use Closure;
use Illuminate\Http\Request;

class AuditAdminAccess
{
    public function handle(Request $request, Closure $next)
    {
        AdminAccess::authorize($request->user());
        $response = $next($request);
        if ($request->isMethod('GET')) {
            AdminAudit::record($request->user()->id, 'admin.page.view', $request->route()?->getName(), outcome: $response->isSuccessful() ? 'success' : 'failed');
        }

        return $response;
    }
}
