<?php

declare(strict_types=1);

/**
 * Test bootstrap: the few JTL constants the plugin logic touches, plus a PSR-4
 * autoloader for Plugin\flizpay\ — the same mapping JTL-Shop applies at
 * runtime. No shop installation and no composer dependencies required.
 */

const LOGLEVEL_ERROR  = 1;
const LOGLEVEL_NOTICE = 2;

const BESTELLUNG_STATUS_STORNO         = -1;
const BESTELLUNG_STATUS_OFFEN          = 1;
const BESTELLUNG_STATUS_IN_BEARBEITUNG = 2;
const BESTELLUNG_STATUS_BEZAHLT        = 3;
const BESTELLUNG_STATUS_VERSANDT       = 4;

const C_WARENKORBPOS_TYP_ARTIKEL = 1;
const C_WARENKORBPOS_TYP_KUPON   = 3;

const ZAHLUNGSART_MAIL_EINGANG = 0x0001;

if (!\function_exists('d__')) {
    function d__(string $domain, string $message): string
    {
        return $message;
    }
}

\spl_autoload_register(static function (string $class): void {
    $prefix = 'Plugin\\flizpay\\';
    if (!\str_starts_with($class, $prefix)) {
        return;
    }
    $relative = \substr($class, \strlen($prefix));
    $file     = __DIR__ . '/../' . \str_replace('\\', '/', $relative) . '.php';
    if (\is_file($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/Doubles.php';
