<?php

declare(strict_types=1);

/**
 * Test bootstrap: minimal stubs so src/Blockonomics.php loads outside a FOSSBilling
 * installation. Only what the class needs to be parsed and constructed — the tests
 * cover pure helpers (secret derivation, URL building, store classification), no DI,
 * no network, no filesystem.
 */

namespace FOSSBilling {
    if (!interface_exists(InjectionAwareInterface::class)) {
        interface InjectionAwareInterface
        {
        }
    }
}

namespace {
    if (!class_exists('Payment_Exception')) {
        class Payment_Exception extends \Exception
        {
            public function __construct(string $message = '', ?array $placeholders = null, int $code = 0)
            {
                parent::__construct($message, $code);
            }
        }
    }

    if (!defined('SYSTEM_URL')) {
        define('SYSTEM_URL', 'https://shop.example/');
    }

    require_once __DIR__ . '/../src/Blockonomics.php';
}
