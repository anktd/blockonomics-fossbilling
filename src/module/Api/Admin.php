<?php

declare(strict_types=1);

/**
 * Blockonomics admin setup endpoints.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Blockonomics\Api;

// This module is installed at runtime, outside Composer's classmap. FOSSBilling 0.8.x requires
// module API classes to extend FOSSBilling\Api\AbstractApi; load the parent explicitly (as the
// guest API does) to be safe in this mirrored-module context.
if (!class_exists('FOSSBilling\\Api\\AbstractApi', false)) {
    require_once PATH_LIBRARY . '/FOSSBilling/Api/AbstractApi.php';
}

class Admin extends \FOSSBilling\Api\AbstractApi
{
    public function callback_url($data = []): array
    {
        $this->loadAdapterClass();
        $this->defaultPaymentModeOn();

        return ['callback_url' => \Payment_Adapter_Blockonomics::getCallbackUrlFromDi($this->di)];
    }

    /**
     * Core installs new gateways with allow_single = 0, so a freshly added Blockonomics tile
     * is invisible to buyers until the admin finds the toggle. Default it ON — but only while
     * the gateway has never been saved (config still NULL), so a deliberate later opt-out
     * sticks. Runs on every settings-page load via callback_url.
     */
    private function defaultPaymentModeOn(): void
    {
        try {
            $gateway = $this->di['db']->findOne('PayGateway', 'gateway = ?', ['Blockonomics']);
            if ($gateway && empty($gateway->config) && !(int) $gateway->allow_single && !(int) $gateway->allow_recurrent) {
                $gateway->allow_single = 1;
                $this->di['db']->store($gateway);
            }
        } catch (\Throwable) {
            // Cosmetic default; never block the settings page.
        }
    }

    public function test_setup($data = []): array
    {
        $data = is_array($data) ? $data : [];
        $this->loadAdapterClass();

        $callbackUrl = \Payment_Adapter_Blockonomics::getCallbackUrlFromDi($this->di);
        $gateway = $this->getGateway((int) ($data['gateway_id'] ?? 0));
        $savedConfig = $this->getGatewayConfig($gateway);
        $savedKey = trim((string) ($savedConfig['api_key'] ?? ''));

        // Test Setup only ever tests the SAVED configuration (the admin JS refuses to run
        // with unsaved edits). Must stay before adapter construction: the ctor throws on an
        // empty key, and this case deserves a plain message, not an exception.
        if ($savedKey === '') {
            return [
                'error' => ['API Key is not set. Please enter your API Key and hit Update Gateway to save changes'],
                'cryptos' => [],
                'callback_url' => $callbackUrl,
            ];
        }

        $adapter = new \Payment_Adapter_Blockonomics($savedConfig);
        $adapter->setDi($this->di);

        return $this->normalizeResult($adapter->testSetup(), $callbackUrl);
    }

    private function loadAdapterClass(): void
    {
        if (class_exists('Payment_Adapter_Blockonomics')) {
            return;
        }

        foreach ([PATH_LIBRARY . '/Payment/Adapter/Blockonomics/Blockonomics.php', PATH_LIBRARY . '/Payment/Adapter/Blockonomics.php'] as $file) {
            if (is_file($file)) {
                include $file;

                return;
            }
        }

        throw new \FOSSBilling\InformationException('Blockonomics payment adapter is not installed.');
    }

    private function getGateway(int $gatewayId)
    {
        $gateway = null;
        if ($gatewayId > 0) {
            $gateway = $this->di['db']->load('PayGateway', $gatewayId);
        }
        if (!$gateway || empty($gateway->id)) {
            $gateway = $this->di['db']->findOne('PayGateway', 'gateway = ?', ['Blockonomics']);
        }

        return $gateway;
    }

    private function getGatewayConfig($gateway): array
    {
        if (!$gateway || empty($gateway->config)) {
            return [];
        }

        $config = json_decode((string) $gateway->config, true);

        return is_array($config) ? $config : [];
    }

    private function normalizeResult(array $result, string $callbackUrl): array
    {
        return [
            'error' => array_values(array_map('strval', $result['error'] ?? [])),
            'cryptos' => array_map(static fn ($c) => [
                'code' => (string) ($c['code'] ?? ''),
                'ok' => (bool) ($c['ok'] ?? false),
                'message' => (string) ($c['message'] ?? ''),
            ], array_values(is_array($result['cryptos'] ?? null) ? $result['cryptos'] : [])),
            'callback_url' => (string) ($result['callback_url'] ?? $callbackUrl),
        ];
    }
}
