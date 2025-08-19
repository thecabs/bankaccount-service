<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class KeycloakTokenService
{
    public function __construct(private ?HttpFactory $http = null)
    {
        $this->http = $this->http ?: Http::factory();
    }

    public function serviceToken(): string
    {
        $base         = rtrim((string) config('services.keycloak.base_url'), '/');
        $realm        = (string) config('services.keycloak.realm');
        $clientId     = (string) config('services.keycloak.client_id');
        $clientSecret = (string) config('services.keycloak.client_secret');

        $url = $base . '/realms/' . $realm . '/protocol/openid-connect/token';

        $resp = $this->http->asForm()->post($url, [
            'grant_type'    => 'client_credentials',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ]);

        if ($resp->failed()) {
            throw new RuntimeException('Keycloak service token error: ' . $resp->status());
        }

        return (string) $resp->json('access_token');
    }
}
