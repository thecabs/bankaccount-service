<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class CheckJWTFromKeycloak
{
    public function handle(Request $request, Closure $next)
    {
        $auth = $request->header('Authorization', '');
        if (!str_starts_with($auth, 'Bearer ')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        $token = substr($auth, 7);

        // Lecture config
        $baseUrl   = rtrim(Config::get('services.keycloak.base_url'), '/');
        $realm     = Config::get('services.keycloak.realm');
        $issuer    = $baseUrl . '/realms/' . $realm;
        $audiences = array_filter(array_map('trim', explode(',', (string) env('KEYCLOAK_ALLOWED_AUDIENCES', ''))));
        $useJwks   = filter_var(env('KEYCLOAK_USE_JWKS', true), FILTER_VALIDATE_BOOL);
        $leeway    = (int) env('KEYCLOAK_LEEWAY', 30);
        JWT::$leeway = max(0, $leeway);

        // Décoder l’en-tête (kid/alg)
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return response()->json(['error' => 'Invalid token'], 401);
        }
        $header = json_decode(JWT::urlsafeB64Decode($parts[0]) ?: '{}', true);
        $alg = (string) ($header['alg'] ?? '');
        $kid = (string) ($header['kid'] ?? '');

        if ($alg !== 'RS256') {
            // Évite l’attaque "alg=none" ou algo inattendu
            return response()->json(['error' => 'Unsupported JWT alg'], 401);
        }

        // Résolution de la clé publique (JWKS > public_key statique)
        $pem = null;

        if ($useJwks && $kid !== '') {
            $jwksUrl = $issuer . '/protocol/openid-connect/certs';
            $jwks = Cache::remember('kc.jwks.' . md5($jwksUrl), (int) env('KEYCLOAK_JWKS_TTL', 300), function () use ($jwksUrl) {
                $resp = Http::timeout((int) env('HTTP_TIMEOUT', 15))->get($jwksUrl);
                if ($resp->failed()) return null;
                return $resp->json();
            });

            if (is_array($jwks) && isset($jwks['keys']) && is_array($jwks['keys'])) {
                foreach ($jwks['keys'] as $jwk) {
                    if (($jwk['kid'] ?? null) === $kid && ($jwk['kty'] ?? '') === 'RSA') {
                        // On privilégie x5c (certificat) -> simple à utiliser comme clé publique
                        if (!empty($jwk['x5c'][0])) {
                            $cert = trim($jwk['x5c'][0]);
                            $pem = "-----BEGIN CERTIFICATE-----\n" . wordwrap($cert, 64, "\n", true) . "\n-----END CERTIFICATE-----";
                        }
                        break;
                    }
                }
            }
        }

        // Fallback: clé publique statique (si JWKS non dispo)
        if (!$pem) {
            $rawKey = config('keycloak.public_key'); // compat avec ton existant
            if (!$rawKey) {
                return response()->json(['error' => 'Keycloak public key missing'], 500);
            }
            $pem = "-----BEGIN PUBLIC KEY-----\n" . wordwrap($rawKey, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
        }

        try {
            // Vérifie signature + exp/nbf/iat (géré par la lib)
            $decoded = JWT::decode($token, new Key($pem, 'RS256'));

            // Vérifs supplémentaires: iss + aud/azp
            if (!isset($decoded->iss) || (string) $decoded->iss !== $issuer) {
                return response()->json(['error' => 'Invalid token issuer'], 401);
            }

            if (!empty($audiences)) {
                // aud peut être string ou array ; azp = authorized party (client)
                $aud = $decoded->aud ?? [];
                $aud = is_array($aud) ? $aud : [$aud];
                $azp = (string) ($decoded->azp ?? '');
                $okAud = (bool) array_intersect($audiences, array_map('strval', $aud));
                $okAzp = $azp !== '' && in_array($azp, $audiences, true);
                if (!$okAud && !$okAzp) {
                    return response()->json(['error' => 'Invalid token audience'], 401);
                }
            }

            // Normalisation des rôles (realm roles)
            $realmRoles = [];
            if (isset($decoded->realm_access) && isset($decoded->realm_access->roles)) {
                $realmRoles = array_map(fn($r) => strtolower((string) $r), (array) $decoded->realm_access->roles);
            }

            // Extractions utiles (compat + ZT)
            $request->attributes->add([
                'external_id' => $decoded->sub ?? null,    // préféré par nos services
                'user_id'     => $decoded->sub ?? null,    // compat avec ancien code
                'agency_id'   => $decoded->agency_id ?? null,
                'token_roles' => $realmRoles,
                'token_data'  => $decoded,                 // laissé tel quel (stdClass) pour data_get()
            ]);
        } catch (\Throwable $e) {
            $payload = ['error' => 'Invalid token'];
            if (config('app.debug')) {
                $payload['message'] = $e->getMessage();
            }
            return response()->json($payload, 401);
        }

        return $next($request);
    }
}
