<?php

namespace App\Logging;

use Illuminate\Log\Logger as IlluminateLogger;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;

class MaskSensitiveData
{
    /**
     * Tap du logger.
     * Laravel peut appeler __invoke($logger, $channelName) => 2 args.
     */
    public function __invoke(IlluminateLogger $logger, ?string $channel = null): void
    {
        // Récupère le Monolog\Logger sous-jacent si possible
        $monolog = method_exists($logger, 'getLogger')
            ? $logger->getLogger()
            : (method_exists($logger, 'getMonolog') ? $logger->getMonolog() : null);

        // Cible pour pushProcessor: le Monolog natif si dispo, sinon le wrapper Illuminate
        $target = ($monolog instanceof MonologLogger) ? $monolog : $logger;

        $target->pushProcessor(function ($record) {
            // Monolog v3
            if ($record instanceof LogRecord) {
                return $record->with(
                    context: $this->maskArray($record->context),
                    extra:   $this->maskArray($record->extra)
                );
            }
            // Monolog v2 (tableau)
            if (is_array($record)) {
                $record['context'] = $this->maskArray($record['context'] ?? []);
                $record['extra']   = $this->maskArray($record['extra'] ?? []);
                return $record;
            }
            return $record;
        });
    }

    private function maskArray(array $data): array
    {
        $keysToMask = [
            'authorization','token','access_token','refresh_token','id_token',
            'password','secret','client_secret','otp','totp','code','pin',
            'phone','telephone','email',
            'fp','fingerprint',
        ];

        $masked = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) { $masked[$k] = $this->maskArray($v); continue; }
            if (is_string($k) && $this->needsMask($k, $keysToMask)) {
                $masked[$k] = $this->maskValue($v);
            } else {
                $masked[$k] = $v;
            }
        }
        return $masked;
    }

    private function needsMask(string $key, array $targets): bool
    {
        $lk = strtolower($key);
        foreach ($targets as $t) {
            if (str_contains($lk, strtolower($t))) return true;
        }
        return false;
    }

    private function maskValue(mixed $v): mixed
    {
        if (!is_string($v)) return $v;
        $len = strlen($v);
        if ($len <= 6) return str_repeat('*', $len);
        return substr($v, 0, 2) . str_repeat('*', max(0, $len - 6)) . substr($v, -4);
    }
}
