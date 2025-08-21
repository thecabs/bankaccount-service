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
        // === Contexte normalisé ZT ===
        $subject = (array) $request->attributes->get('zt.subject', []);
        $context = (array) $request->attributes->get('zt.context', []);

        $roles    = array_map('strtolower', (array) data_get($subject, 'roles', []));
        $agencyId = (string) data_get($subject, 'agency_id', '');
        $acrArr   = (array) data_get($subject, 'acr', []);
        $acr      = (string) ($acrArr[0] ?? '');
        $amr      = (array) data_get($subject, 'amr', []);

        // Sensibilité posée par ResourceTag
        $sensitivity = strtoupper((string) $request->attributes->get('zt.resource.sensitivity', 'GENERAL'));

        // Action
        $method  = strtoupper($request->method());
        $isWrite = in_array($method, ['POST','PUT','PATCH','DELETE'], true)
                   || strtolower((string) $request->attributes->get('zt.action', '')) === 'write';

        // Device trust / risk (optionnels: si non posés, restent null/0)
        $deviceTrust = data_get($context, 'device.trust', null);
        $risk        = (int) data_get($context, 'risk', 0);
        $hour        = (int) (data_get($context, 'hour', now()->hour));

        // Token de service (client_credentials) via azp whitelist
        $tok         = (array) $request->attributes->get('token_data', []);
        $clientId    = strtolower((string) data_get($tok, 'azp', ''));
        $svcWhitelist = array_map('strtolower', (array) config('http.pdp.service_bypass_azp', []));
        $isServiceToken = $clientId !== '' && in_array($clientId, $svcWhitelist, true);

        // Bypass par rôles (admin/svc_*)
        $allowBypassRoles  = array_map('strtolower', (array) config('http.pdp.admin_bypass_roles', ['admin','svc_bankaccount']));
        $isAdminBypassRole = (bool) array_intersect($roles, $allowBypassRoles);

        // Politique : exiger MFA pour écriture FINANCIAL (humains), activable par conf
        $requireMfaOnAdminWrites = (bool) config('http.pdp.require_mfa_for_admin_financial_writes', false);

        // Détection MFA robuste
        $hasMfa = $this->hasMfa($acr, $acrArr, $amr);

        // ===== Décision par défaut =====
        $decision   = 'allow';
        $reason     = 'ok';
        $obligation = null;

        // ===== Règles FINANCIAL =====
        if ($sensitivity === 'FINANCIAL') {
            if ($isServiceToken || $isAdminBypassRole) {
                // allow, mais on logue
            } else {
                if ($isWrite && $requireMfaOnAdminWrites) {
                    if (!$hasMfa) {
                        $decision   = 'deny';
                        $reason     = 'mfa_required_on_financial_write';
                        $obligation = ['type' => 'mfa', 'acr_values' => 'mfa'];
                    }
                } else {
                    $minTrust = (int) config('http.pdp.financial_min_device_trust', 0);
                    if ($minTrust > 0 && $deviceTrust !== null && $deviceTrust < $minTrust) {
                        $decision   = 'deny';
                        $reason     = 'low_device_trust';
                        $obligation = ['type' => 'device_trust', 'min' => $minTrust];
                    }
                }
            }
        }

        // ===== Logs d’audit =====
        $logPayload = [
            'request_id' => $request->headers->get('X-Request-Id'),
            'sub'        => data_get($subject, 'sub'),
            'roles'      => $roles,
            'agency_id'  => $agencyId,
            'action'     => $isWrite ? 'write' : 'read',
            'sensitivity'=> $sensitivity,
            'risk'       => $risk,
            'obligations'=> $obligation ?? [],
            'acr'        => $acrArr,
            'amr'        => $amr,
            'has_mfa'    => $hasMfa,
            'hour'       => $hour,
            'ip'         => $request->ip(),
            'azp'        => $clientId,
            'service'    => $isServiceToken,
            'decision'   => $decision,
            'reason'     => $reason,
            'path'       => $request->path(),
            'method'     => $method,
        ];
        Log::channel('audit')->info('pdp.decision', $logPayload);

        if ($decision === 'deny') {
            return response()->json([
                'error'      => 'Forbidden',
                'obligation' => $obligation,
                'reason'     => $reason,
            ], 403);
        }

        return $next($request);
    }

    private function hasMfa(string $acr, array $acrArr, array $amr): bool
    {
        if ($acr === 'mfa') return true;
        if (ctype_digit($acr) && (int) $acr >= 2) return true;

        foreach ($acrArr as $a) {
            if ($a === 'mfa') return true;
            if (is_string($a) && ctype_digit($a) && (int) $a >= 2) return true;
        }

        $amrLower = array_map('strtolower', $amr);
        foreach (['otp','totp','webauthn','hwk','sms','email'] as $factor) {
            if (in_array($factor, $amrLower, true)) return true;
        }
        return false;
    }
}
