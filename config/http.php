<?php

return [
    // ...
    'pdp' => [
        'admin_bypass_roles' => [
            'admin', 'superadmin', 'bo_admin', 'bo_superadmin', 'svc_ceiling', 'svc_wallet', 'svc_bankaccount',
        ],
        'service_bypass_azp' => [
            'wallet-service-client',
            'backoffice-service',
            'userceiling-service-client',
            'bankaccount-service-client',
            'userm-service-client',
        ],
        'financial_min_device_trust' => env('PDP_FINANCIAL_MIN_DEVICE_TRUST', 0),

        // ✨ Nouveau : exiger MFA sur écriture FINANCIAL (humains, admin inclus) — défaut: OFF (pas de régression)
        'require_mfa_for_admin_financial_writes' => env('PDP_REQUIRE_MFA_FOR_ADMIN_FINANCIAL_WRITES', false),
    ],
];
