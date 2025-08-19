<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PolicyDecision
{
    public function handle(Request $request, Closure $next): Response
    {
        // === Contexte normalisé ===
        $ctx   = (array) $request->attributes->get('ctx', []);
        $tags  = array_map('strtoupper', (array) $request->attributes->get('resource_tags', []));

        $roles    = array_map('strtolower', (array) data_get($ctx, 'actor.roles', []));
        $agencyId = (string) data_get($ctx, 'actor.agency_id', '');
        $ip       = $request->ip();

        // ACR/AMR robustes (string ou array)
        $acrRaw = data_get($ctx, 'actor.acr', '');
        $acrArr = is_array($acrRaw) ? $acrRaw : [$acrRaw];
        $acr    = (string) (is_array($acrRaw) ? ($acrRaw[0] ?? '') : $acrRaw);
        $amr    = (array)  data_get($ctx, 'actor.amr', []);

        $devTrust = data_get($ctx, 'device.trust'); // 0..100 ou null

        // Action & sensibilité
        $method = strtoupper($request->method());
        $isWrite = in_array($method, ['POST','PUT','PATCH','DELETE'], true)
            || strtolower((string) $request->attributes->get('zt.action', '')) === 'write';

        $sensitivity = 'GENERAL';
        if (in_array('FINANCIAL', $tags, true)) $sensitivity = 'FINANCIAL';
        if (in_array('PII',        $tags, true)) $sensitivity = 'PII';

        // Token de service (client_credentials) via azp whitelist
        $tok      = (array) $request->attributes->get('token_data', []);
        $clientId = strtolower((string) data_get($tok, 'azp', ''));
        $svcWhitelist = array_map('strtolower', (array) config('http.pdp.service_bypass_azp', []));
        $isServiceToken = $clientId !== '' && in_array($clientId, $svcWhitelist, true);

        // Bypass par rôles (admin/svc_*)
        $allowBypassRoles = array_map('strtolower', (array) config('http.pdp.admin_bypass_roles', []));
        $isAdminBypassRole = (bool) array_intersect($roles, $allowBypassRoles);

        // Politique : exiger MFA pour écriture FINANCIAL (humains), activable par conf
        $requireMfaOnAdminWrites = (bool) config('http.pdp.require_mfa_for_admin_financial_writes', false);

        // Détection MFA
        $hasMfa = $this->hasMfa($acr, $acrArr, $amr);

        // ===== Décision par défaut =====
        $decision   = 'allow';
        $reason     = 'ok';
        $obligation = null;

        // ===== Règles FINANCIAL =====
        if ($sensitivity === 'FINANCIAL') {
            // 1) Tokens de service : toujours OK (décisions auditées ailleurs)
            if ($isServiceToken) {
                // rien
            }
            // 2) Humains
            else {
                // 2.a) Écritures : exigence MFA configurable (admin compris)
                if ($isWrite && $requireMfaOnAdminWrites) {
                    if (!$hasMfa) {
                        $decision   = 'deny';
                        $reason     = 'mfa_required_on_financial_write';
                        $obligation = ['type' => 'mfa', 'acr_values' => 'mfa'];
                    }
                } else {
                    // 2.b) Lectures (ou écriture sans durcissement)
                    // Si tu veux exiger device_trust minimal, active ce bloc
                    $minTrust = (int) config('http.pdp.financial_min_device_trust', 0);
                    if ($minTrust > 0 && $devTrust !== null && $devTrust < $minTrust) {
                        $decision   = 'deny';
                        $reason     = 'low_device_trust';
                        $obligation = ['type' => 'device_trust', 'min' => $minTrust];
                    }
                }
            }
        }

        // ===== Logs d’audit (toujours) =====
        $logPayload = [
            'request_id' => $request->headers->get('X-Request-Id'),
            'sub'        => data_get($ctx, 'actor.sub'),
            'roles'      => $roles,
            'agency_id'  => $agencyId,
            'action'     => $isWrite ? 'write' : 'read',
            'sensitivity'=> $sensitivity,
            'risk'       => (int) data_get($ctx, 'risk', 0),
            'obligations'=> $obligation ?? [],
            'acr'        => $acrArr,
            'amr'        => $amr,
            'has_mfa'    => $hasMfa,
            'hour'       => (int) (data_get($ctx, 'hour', now()->hour)),
            'ip'         => $ip,
            'azp'        => $clientId,
            'service'    => $isServiceToken,
            'decision'   => $decision,
            'reason'     => $reason,
            'path'       => $request->path(),
            'method'     => $method,
            'tags'       => $tags,
        ];
        Log::channel('audit')->info('pdp.decision', $logPayload);
        Log::info('pdp.decision', $logPayload); // si tu gardes aussi sur le canal 'local'

        if ($decision === 'deny') {
            return response()->json([
                'error'      => 'Forbidden',
                'obligation' => $obligation,
                'reason'     => $reason,
            ], 403);
        }

        return $next($request);
    }

    /**
     * Détermine la présence MFA de façon robuste.
     * - acr string: 'mfa' ou entier >= 2
     * - acr array : contient 'mfa' ou un entier >= 2
     * - amr       : contient 'otp'
     */
    private function hasMfa(string $acr, array $acrArr, array $amr): bool
    {
        // cas string simple
        if ($acr === 'mfa') return true;
        if (ctype_digit($acr) && (int) $acr >= 2) return true;

        // cas array d'ACR
        foreach ($acrArr as $a) {
            if ($a === 'mfa') return true;
            if (is_string($a) && ctype_digit($a) && (int) $a >= 2) return true;
        }

        // AMR
        if (in_array('otp', $amr, true)) return true;

        return false;
    }
}
