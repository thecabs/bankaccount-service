<?php

namespace App\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;

class MaskSensitiveData extends JsonFormatter
{
    /** clés masquées (minuscule) */
    private array $maskKeys = [
        'authorization','access_token','refresh_token','token','client_secret','password',
        'cle_rib','numero_compte','identifiant_national','identifiant_national_hash',
        'telephone_reference','meta_core_ref','x-api-key','api_key',
    ];

    public function __construct()
    {
        // Laisse la config par défaut de JsonFormatter (Monolog v3)
        parent::__construct();
    }

    /** Monolog v3: reçoit un LogRecord (pas un array) */
    public function format(LogRecord $record): string
    {
        // Masquer dans message/ctx/extra
        $msg    = $this->maskString($record->message);
        $ctx    = $this->maskArray($record->context);
        $extra  = $this->maskArray($record->extra);

        // Recrée un LogRecord masqué puis délègue à JsonFormatter
        $masked = new LogRecord(
            $record->datetime,
            $record->channel,
            $record->level instanceof Level ? $record->level : Level::from($record->level->value),
            $msg,
            $ctx,
            $extra
        );

        return parent::format($masked);
    }

    /* ---------------- helpers ---------------- */

    private function maskArray(array $a): array
    {
        foreach ($a as $k => $v) {
            if (is_array($v)) { $a[$k] = $this->maskArray($v); continue; }
            if ($this->shouldMask((string)$k) && is_scalar($v)) {
                $a[$k] = $this->maskString((string)$v);
            }
        }
        return $a;
    }

    private function shouldMask(string $key): bool
    {
        $k = strtolower($key);
        if (in_array($k, $this->maskKeys, true)) return true;
        // règles génériques
        return (bool) preg_match('/(secret|token|pass|authorization|cle|rib)/i', $k);
    }

    private function maskString(string $v): string
    {
        $v = trim($v);
        if ($v === '') return '';
        $len = strlen($v);
        if ($len <= 8) return str_repeat('*', $len);
        return substr($v, 0, 4) . str_repeat('*', max(0, $len - 8)) . substr($v, -4);
    }
}
