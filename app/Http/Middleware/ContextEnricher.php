<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ContextEnricher
{
    public function handle(Request $req, Closure $next)
    {
        // Fail-fast: token_data attendu (injecté par le middleware 'keycloak')
        if (!$req->attributes->has('token_data')) {
            $requestId = $req->headers->get('X-Request-Id') ?: Str::uuid()->toString();
            $res = response()->json(['error' => 'unauthorized', 'message' => 'Missing token_data'], 401);
            $res->headers->set('X-Request-Id', $requestId);
            return $res;
        }

        $token = $req->attributes->get('token_data', []);

        // Rôles realm + resource_access (clients listés dans KEYCLOAK_RESOURCE_ROLE_CLIENTS)
        $realmRoles = (array) data_get($token, 'realm_access.roles', []);
        $clientRoles = [];
        $clientIds = array_filter(array_map('trim', explode(',', (string) env('KEYCLOAK_RESOURCE_ROLE_CLIENTS', ''))));
        foreach ($clientIds as $cid) {
            $clientRoles = array_merge($clientRoles, (array) data_get($token, "resource_access.$cid.roles", []));
        }
        $allRoles = array_values(array_unique(array_map('strval', array_merge($realmRoles, $clientRoles))));

        $subject = [
            'sub'       => data_get($token, 'sub'),
            'roles'     => $allRoles,
            'agency_id' => data_get($token, 'agency_id'),
            // ACR (assurance) sous forme de tableau + compatibilité amr
            'acr'       => (array) (data_get($token, 'acr') ? [data_get($token, 'acr')] : []),
            'amr'       => (array) data_get($token, 'amr', []),
        ];

        // Heure réelle ou surchargée (tests) via X-Debug-Hour
        $debugHour = $req->headers->get('X-Debug-Hour');
        $hour = is_numeric($debugHour) ? max(0, min(23, (int) $debugHour)) : (int) now()->format('G');

        $ctx = [
            'hour'       => $hour,
            'request_id' => $req->headers->get('X-Request-Id') ?: Str::uuid()->toString(),
        ];

        // Propager un X-Request-Id systématique (utile pour audit + corrélation)
        $req->headers->set('X-Request-Id', $ctx['request_id']);

        $req->attributes->set('zt.subject', $subject);
        $req->attributes->set('zt.context', $ctx);
        $req->attributes->set('zt.action', $req->isMethod('GET') ? 'read' : 'write');

        $res = $next($req);
        // Ajoute l'ID de corrélation côté réponse également
        try {
            $res->headers->set('X-Request-Id', $ctx['request_id']);
        } catch (\Throwable $e) {
            // no-op
        }
        return $res;
    }
}
