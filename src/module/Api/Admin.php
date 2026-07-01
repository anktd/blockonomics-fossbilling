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

        return ['callback_url' => \Payment_Adapter_Blockonomics::getCallbackUrlFromDi($this->di)];
    }

    public function test_setup($data = []): array
    {
        $data = is_array($data) ? $data : [];
        $this->loadAdapterClass();

        $callbackUrl = \Payment_Adapter_Blockonomics::getCallbackUrlFromDi($this->di);
        $gateway = $this->getGateway((int) ($data['gateway_id'] ?? 0));
        $savedConfig = $this->getGatewayConfig($gateway);
        $savedKey = trim((string) ($savedConfig['api_key'] ?? ''));
        $typedKey = trim((string) ($data['api_key'] ?? ''));
        $apiKey = $typedKey !== '' ? $typedKey : $savedKey;

        if ($apiKey === '') {
            return [
                'message' => 'Blockonomics setup needs attention.',
                'success' => [],
                'error' => ['Enter your Blockonomics API key before running Test Setup.'],
                'store' => null,
                'cryptos' => [],
                'callback_url' => $callbackUrl,
                'actions_taken' => [],
            ];
        }

        $config = $savedConfig;
        $config['api_key'] = $apiKey;

        $adapter = new \Payment_Adapter_Blockonomics($config);
        $adapter->setDi($this->di);
        $result = $adapter->testSetup();
        $result = $this->normalizeResult($result, $callbackUrl);

        if ($typedKey !== '' && !hash_equals($savedKey, $typedKey)) {
            $result['note'] = 'This tested the API key typed in the form, which is not saved yet — click Update Gateway to keep it.';
        }

        return $result;
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
            'message' => (string) ($result['message'] ?? 'Blockonomics setup finished.'),
            'success' => array_values(array_map('strval', $result['success'] ?? [])),
            'error' => array_values(array_map('strval', $result['error'] ?? [])),
            'store' => $result['store'] ?? null,
            'cryptos' => array_map(static fn ($c) => [
                'code' => (string) ($c['code'] ?? ''),
                'ok' => (bool) ($c['ok'] ?? false),
                'message' => (string) ($c['message'] ?? ''),
            ], array_values(is_array($result['cryptos'] ?? null) ? $result['cryptos'] : [])),
            'callback_url' => (string) ($result['callback_url'] ?? $callbackUrl),
            'actions_taken' => array_values(array_map('strval', $result['actions_taken'] ?? [])),
        ];
    }
}
