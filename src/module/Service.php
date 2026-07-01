<?php

declare(strict_types=1);

/**
 * Blockonomics module — companion to the Blockonomics payment adapter.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Blockonomics;

use FOSSBilling\InjectionAwareInterface;

class Service implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function uninstall(): bool
    {
        try {
            if ($this->di !== null) {
                $this->di['db']->exec('DROP TABLE IF EXISTS blockonomics_order');
            }
        } catch (\Throwable) {
            // Uninstall should stay idempotent; a missing/locked table must not block core cleanup.
        }

        try {
            if (defined('PATH_ROOT')) {
                $logo = PATH_ROOT . '/public/gateways/blockonomics.png';
                if (is_file($logo)) {
                    @unlink($logo);
                }
            }
        } catch (\Throwable) {
            // The module directory is removed by FOSSBilling after this hook returns.
        }

        return true;
    }
}
