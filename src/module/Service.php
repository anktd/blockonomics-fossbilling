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
}
