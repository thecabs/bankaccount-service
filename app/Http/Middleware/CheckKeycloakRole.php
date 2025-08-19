<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckKeycloakRole
{
    public function handle(Request $request, Closure $next, $requiredRolesCsv)
    {
        // On utilise d’abord la normalisation posée par CheckJWTFromKeycloak
        $userRoles = $request->attributes->get('token_roles');
        if (!is_array($userRoles)) {
            // fallback si l’attribut n’est pas présent
            $tokenData = $request->attributes->get('token_data');
            $userRoles = [];
            if ($tokenData && isset($tokenData->realm_access->roles)) {
                $userRoles = array_map('strtolower', (array) $tokenData->realm_access->roles);
            }
        }

        if (!$userRoles || !is_array($userRoles)) {
            return response()->json(['error' => 'Access Denied - Token data missing'], 403);
        }

        // Rôles requis (ANY-of sémantique, ex: "admin,agent_kyc")
        $requiredRoles = array_values(array_filter(array_map(
            fn($r) => strtolower(trim($r)),
            explode(',', (string) $requiredRolesCsv)
        )));

        foreach ($requiredRoles as $role) {
            if (in_array($role, $userRoles, true)) {
                return $next($request);
            }
        }

        $payload = [
            'error'           => 'Access Denied - Missing role',
            'required_roles'  => $requiredRoles,
        ];
        if (config('app.debug')) {
            $payload['user_roles'] = $userRoles;
        }

        return response()->json($payload, 403);
    }
}
