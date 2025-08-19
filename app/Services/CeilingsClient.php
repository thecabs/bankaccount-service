<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CeilingsClient
{
    /** Client HTTP prêt (timeouts + retries) */
    private PendingRequest $http;

    /** Service d’obtention de tokens Keycloak (client_credentials + cache) */
    private KeycloakTokenService $kc;

    /** Base URL du microservice UserCeiling (ex: http://localhost:8001/api) */
    private string $baseUrl;

    /**
     * Signature alignée avec ton stacktrace :
     * __construct(Object(KeycloakTokenService), Object(Illuminate\Http\Client\Factory))
     */
    public function __construct(KeycloakTokenService $kc, HttpFactory $factory)
    {
        $this->kc      = $kc;
        $this->baseUrl = rtrim((string) config('services.ceiling.base_url'), '/');

        $this->http = $factory
            ->timeout((int) config('http.timeout', 20))
            ->retry(2, 200)      // 2 retries, 200ms backoff
            ->acceptJson();
    }

    /**
     * Assure qu’un plafond par défaut existe pour l’utilisateur (opération idempotente).
     * POST {ceiling}/internal/ceilings/ensure
     */
    public function ensureDefault(string $externalId, ?string $currency = null, ?string $requestId = null): array
    {
        if ($this->baseUrl === '') {
            throw new RuntimeException('CONFIG: services.ceiling.base_url manquant.');
        }

        $url = $this->baseUrl . '/internal/ceilings/ensure';
        $headers = $requestId ? ['X-Request-Id' => $requestId] : [];

        try {
            $resp = $this->http
                ->withToken($this->kc->getServiceToken())
                ->withHeaders($headers)
                ->post($url, array_filter([
                    'external_id' => $externalId,
                    'currency'    => $currency ? strtoupper($currency) : null,
                ]));
        } catch (ConnectionException $e) {
            Log::warning('ceiling.ensure.connection_error', [
                'external_id' => $externalId,
                'error'       => $e->getMessage(),
            ]);
            throw new RuntimeException('UserCeiling non joignable');
        }

        if ($resp->failed()) {
            Log::warning('ceiling.ensure.failed', [
                'external_id' => $externalId,
                'status'      => $resp->status(),
                'body'        => $resp->body(),
            ]);
            throw new RuntimeException('UserCeiling erreur: ' . $resp->status());
        }

        return $resp->json() ?? [];
    }

    /**
     * Vérifie si un montant respecte les plafonds courants.
     * POST {ceiling}/ceilings/check-limit
     */
    public function checkLimit(string $externalId, float $amount, string $period = 'daily', ?string $requestId = null): bool
    {
        if ($this->baseUrl === '') {
            throw new RuntimeException('CONFIG: services.ceiling.base_url manquant.');
        }

        $url = $this->baseUrl . '/ceilings/check-limit';
        $headers = $requestId ? ['X-Request-Id' => $requestId] : [];

        try {
            $resp = $this->http
                ->withToken($this->kc->getServiceToken())
                ->withHeaders($headers)
                ->post($url, [
                    'external_id' => $externalId,
                    'amount'      => $amount,
                    'period'      => $period,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('ceiling.check.connection_error', [
                'external_id' => $externalId,
                'amount'      => $amount,
                'period'      => $period,
                'error'       => $e->getMessage(),
            ]);
            throw new RuntimeException('UserCeiling non joignable');
        }

        if ($resp->failed()) {
            Log::warning('ceiling.check.failed', [
                'external_id' => $externalId,
                'status'      => $resp->status(),
                'body'        => $resp->body(),
            ]);
            throw new RuntimeException('UserCeiling erreur: ' . $resp->status());
        }

        return (bool) ($resp->json('allowed') ?? false);
    }
}
