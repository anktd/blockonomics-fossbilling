<?php

declare(strict_types=1);

/**
 * Blockonomics guest callback endpoint.
 *
 * Reached at: GET /api/guest/blockonomics/callback?secret=…&status=…&addr=…&value=…&txid=…
 *
 * Blockonomics requires the callback URL to be CONSTANT (it exact-matches it against a
 * registered store), so it cannot carry a per-invoice id, and FOSSBilling's ipn.php
 * requires one. This endpoint bridges the two: it resolves the invoice from the Bitcoin
 * ADDRESS (via the blockonomics_order map the adapter wrote in getHtml), then hands off to
 * the core transaction pipeline with the resolved invoice_id. Guest API endpoints are
 * CSRF-exempt, so Blockonomics' external GET is accepted.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Blockonomics\Api;

// This module is installed at runtime (mirrored into modules/Blockonomics), so it lives outside
// Composer's classmap. FOSSBilling 0.8.x routes module API calls through FOSSBilling\Api\Dispatcher,
// which requires the class to extend FOSSBilling\Api\AbstractApi. That parent resolves via the
// FOSSBilling\ PSR-4 autoloader, but load it explicitly first to be safe in this mirrored-module
// context (mirrors the adapter-include pattern used in callback() below).
if (!class_exists('FOSSBilling\\Api\\AbstractApi', false)) {
    require_once PATH_LIBRARY . '/FOSSBilling/Api/AbstractApi.php';
}

class Guest extends \FOSSBilling\Api\AbstractApi
{
    public function callback($data)
    {
        $data = is_array($data) ? $data : [];
        $addr = isset($data['addr']) ? (string) $data['addr'] : '';
        $txid = isset($data['txid']) ? (string) $data['txid'] : '';

        if ($addr === '' && $txid === '') {
            return ['result' => 'ignored', 'reason' => 'no addr or txid'];
        }

        // The adapter class isn't autoloadable from its subdirectory — load it by explicit
        // path, the same way the core's getAdapterClassName() does.
        if (!class_exists('Payment_Adapter_Blockonomics')) {
            foreach ([PATH_LIBRARY . '/Payment/Adapter/Blockonomics/Blockonomics.php', PATH_LIBRARY . '/Payment/Adapter/Blockonomics.php'] as $file) {
                if (is_file($file)) {
                    include $file;

                    break;
                }
            }
        }
        if (!class_exists('Payment_Adapter_Blockonomics')) {
            $this->di['logger']->info('Blockonomics callback ignored: payment adapter is not installed.');

            return ['result' => 'ignored', 'reason' => 'adapter not installed'];
        }

        // Verify the secret (defence in depth). The adapter derives the same stable token from
        // FOSSBilling's per-install secret for every callback URL call site.
        $expectedSecret = \Payment_Adapter_Blockonomics::getCallbackSecretFromConfig();
        $providedSecret = (string) ($data['secret'] ?? '');
        if (!hash_equals($expectedSecret, $providedSecret)) {
            $this->di['logger']->info('Blockonomics callback rejected: secret mismatch (addr ' . $addr . ').');

            return ['result' => 'ignored', 'reason' => 'secret mismatch'];
        }

        // BTC addresses are per-invoice. USDT uses a shared address and its documented
        // callback fields do not include the coin, so recognize the pre-bound USDT tx hash.
        $txOrder = $txid !== '' ? $this->di['db']->findOne('blockonomics_order', 'txid = ?', [$txid]) : null;
        if ($txOrder && strtoupper((string) ($txOrder->crypto ?? '')) === 'USDT') {
            $order = $txOrder;
        } else {
            $order = $this->di['db']->findOne('blockonomics_order', 'addr = ?', [$addr]);
            $order = $order ?: $txOrder;
        }
        if (!$order) {
            $this->di['logger']->info('Blockonomics callback: no matching order for addr ' . $addr . ', txid ' . $txid . '.');

            return ['result' => 'ignored', 'reason' => 'no matching order'];
        }

        // Hand off to the core transaction pipeline with the resolved invoice_id. This creates
        // the Transaction and calls Payment_Adapter_Blockonomics::processTransaction(), which
        // performs the confirmation, amount, and dedup gates, settling only sufficient payments.
        //
        // We set txn_id to the same unique key the adapter uses (txid-addr) so the core's
        // built-in dedup in ServiceTransaction::create() returns the already-processed
        // transaction WITHOUT creating a new row when Blockonomics re-sends a confirmed callback.
        $ipn = [
            'invoice_id' => (int) $order->invoice_id,
            'gateway_id' => (int) $order->gateway_id,
            'txn_id' => \Payment_Adapter_Blockonomics::uniqueTxnId($txid, $addr),
            'source' => 'blockonomics',
            'get' => $data,
            'post' => [],
            'http_raw_post_data' => '',
            'server' => [],
        ];

        $this->di['mod_service']('Invoice', 'Transaction')->createAndProcess($ipn);

        return ['result' => 'ok'];
    }

    /**
     * Read-only payment state for the buyer waiting page.
     *
     * POST /api/guest/blockonomics/payment_status  body: { invoice_hash, order_id }
     */
    public function payment_status($data)
    {
        $neutral = $this->neutralPaymentStatus();
        $data = is_array($data) ? $data : [];
        $invoiceHash = (string) ($data['invoice_hash'] ?? '');
        $orderId = isset($data['order_id']) ? (int) $data['order_id'] : 0;

        // The adapter class isn't autoloadable from its subdirectory — load it by explicit
        // path, the same way the core's getAdapterClassName() does.
        if (!class_exists('Payment_Adapter_Blockonomics')) {
            foreach ([PATH_LIBRARY . '/Payment/Adapter/Blockonomics/Blockonomics.php', PATH_LIBRARY . '/Payment/Adapter/Blockonomics.php'] as $file) {
                if (is_file($file)) {
                    include $file;

                    break;
                }
            }
        }
        if (!class_exists('Payment_Adapter_Blockonomics')) {
            return $neutral;
        }

        if ($invoiceHash === '' || $orderId <= 0) {
            return $neutral;
        }

        $invoice = $this->di['db']->findOne('Invoice', 'hash = ?', [$invoiceHash]);
        if (!$invoice) {
            return $neutral;
        }

        try {
            $order = $this->di['db']->load('blockonomics_order', $orderId);
        } catch (\Throwable $e) {
            return $neutral;
        }
        if (!$order || (int) $order->invoice_id !== (int) $invoice->id) {
            return $neutral;
        }

        $status = $order->status === null ? null : (int) $order->status;
        $crypto = strtoupper((string) ($order->crypto ?? ''));
        $crypto = in_array($crypto, ['BTC', 'USDT'], true) ? $crypto : null;
        $paid = (string) $invoice->status === \Model_Invoice::STATUS_PAID;
        $payable = in_array((string) $invoice->status, [\Model_Invoice::STATUS_UNPAID, \Model_Invoice::STATUS_PAID], true);
        $submittedOnly = $crypto === 'USDT'
            && $status === 0
            && (int) ($order->value_satoshi ?? 0) === 0
            && (string) ($order->txid ?? '') !== '';

        return [
            'pending' => $status !== null && $status >= 0 && $status < 2,
            'crypto' => $crypto,
            'status' => $status,
            'required' => 2,
            'submitted_only' => $submittedOnly,
            'stale' => (bool) \Payment_Adapter_Blockonomics::isStaleUnconfirmed($order),
            'paid' => $paid,
            'payable' => $payable,
            'underpaid' => (bool) (\Payment_Adapter_Blockonomics::isUnderpaidOrder($order) && !$paid),
            'reverted' => (bool) (\Payment_Adapter_Blockonomics::isRevertedOrder($order) && !$paid),
            'order_id' => (int) $order->id,
        ];
    }

    /**
     * Buyer picked a coin in the getHtml chooser. Generate the address for that coin and
     * return its payment-view HTML (the adapter does the work). Returns an HTML string.
     *
     * POST /api/guest/blockonomics/checkout  body: { invoice_hash, crypto }
     */
    public function checkout($data)
    {
        $data = is_array($data) ? $data : [];
        $invoiceHash = (string) ($data['invoice_hash'] ?? '');
        $crypto = strtoupper((string) ($data['crypto'] ?? ''));

        if ($invoiceHash === '' || !in_array($crypto, ['BTC', 'USDT'], true)) {
            throw new \FOSSBilling\InformationException('Missing or invalid checkout parameters.');
        }

        $invoice = $this->di['db']->findOne('Invoice', 'hash = ?', [$invoiceHash]);
        if (!$invoice) {
            throw new \FOSSBilling\InformationException('Invoice not found.');
        }
        // Don't issue addresses for invoices that can no longer be paid (also protects the
        // merchant's xpub gap limit from being burned via repeated calls).
        if ($invoice->status !== \Model_Invoice::STATUS_UNPAID) {
            throw new \FOSSBilling\InformationException('This invoice can no longer be paid.');
        }

        $gateway = $this->di['db']->findOne('PayGateway', 'gateway = ?', ['Blockonomics']);
        if (!$gateway) {
            throw new \FOSSBilling\InformationException('Blockonomics gateway is not installed.');
        }

        $adapter = $this->di['mod_service']('Invoice', 'PayGateway')->getPaymentAdapter($gateway, $invoice);

        return $adapter->renderCheckout($invoice, $crypto);
    }

    /**
     * USDT finish: the web3-payment widget redirects the buyer here after the on-chain
     * transfer, appending the transaction hash. We hand the txhash to Blockonomics' monitor_tx
     * (so callbacks fire for it) and store it on the order so the inbound callback resolves to
     * this invoice by txid, then send the buyer back to the invoice. In test mode, monitor_tx
     * with the test txhash makes Blockonomics generate the confirming callback automatically.
     *
     * GET /api/guest/blockonomics/finish?invoice_hash=…&txhash=…  (browser navigation)
     */
    public function finish($data)
    {
        $data = is_array($data) ? $data : [];
        $invoiceHash = (string) ($data['invoice_hash'] ?? '');
        $txhash = trim((string) ($data['txhash'] ?? ''));

        $invoice = null;
        $order = null;
        if ($invoiceHash !== '') {
            try {
                $invoice = $this->di['db']->findOne('Invoice', 'hash = ?', [$invoiceHash]);
            } catch (\Throwable $e) {
                $this->di['logger']->info('Blockonomics finish invoice lookup failed: ' . $e->getMessage());
            }
        }

        // Mirror checkout(): never bind a txhash to an invoice that can no longer be paid —
        // the callback credits the client before the settlement gate, so a hash bound to a
        // non-payable invoice would still mint account credit.
        if ($invoice && $txhash !== '' && $invoice->status !== \Model_Invoice::STATUS_UNPAID) {
            $this->di['logger']->info('Blockonomics finish skipped: invoice ' . $invoice->id . ' can no longer be paid.');
        } elseif ($invoice && $txhash !== '') {
            if (!class_exists('Payment_Adapter_Blockonomics')) {
                foreach ([PATH_LIBRARY . '/Payment/Adapter/Blockonomics/Blockonomics.php', PATH_LIBRARY . '/Payment/Adapter/Blockonomics.php'] as $file) {
                    if (is_file($file)) {
                        include $file;

                        break;
                    }
                }
            }

            if (!class_exists('Payment_Adapter_Blockonomics')) {
                $this->di['logger']->info('Blockonomics finish skipped: payment adapter is not installed.');
            } elseif (!\Payment_Adapter_Blockonomics::isValidUsdtTxhash($txhash)) {
                $this->di['logger']->info('Blockonomics finish skipped: invalid USDT txhash.');
            } else {
                try {
                    $order = $this->di['db']->findOne('blockonomics_order', 'invoice_id = ? AND crypto = ? ORDER BY id DESC', [(int) $invoice->id, 'USDT']);
                    // Skip if this txhash is already tied to a different order (dedup).
                    $other = $order ? $this->di['db']->findOne('blockonomics_order', 'txid = ? AND id != ?', [$txhash, (int) $order->id]) : null;

                    if ($order && !$other) {
                        $currentTxid = (string) ($order->txid ?? '');
                        if ($currentTxid === $txhash) {
                            // Idempotent revisit.
                        } elseif ($currentTxid === '') {
                            $order->txid = $txhash;
                            if ($order->status === null) {
                                $order->status = 0;
                            }
                            $order->updated_at = date('Y-m-d H:i:s');
                            $this->di['db']->store($order);
                            $this->monitorUsdtTxhash($order, $txhash);
                        } else {
                            $newOrder = $this->di['db']->dispense('blockonomics_order');
                            $newOrder->invoice_id = (int) $order->invoice_id;
                            $newOrder->gateway_id = (int) $order->gateway_id;
                            $newOrder->crypto = (string) $order->crypto;
                            $newOrder->addr = (string) $order->addr;
                            $newOrder->expected_satoshi = (int) $order->expected_satoshi;
                            $newOrder->currency = (string) $order->currency;
                            $newOrder->txid = $txhash;
                            $newOrder->value_satoshi = 0;
                            $newOrder->status = 0;
                            $newOrder->created_at = date('Y-m-d H:i:s');
                            $newOrder->updated_at = date('Y-m-d H:i:s');
                            $this->di['db']->store($newOrder);
                            $order = $newOrder;
                            $this->monitorUsdtTxhash($order, $txhash);
                        }
                    }
                } catch (\Throwable $e) {
                    $this->di['logger']->info('Blockonomics finish storage failed: ' . $e->getMessage());
                }
            }
        }

        if (!$invoice) {
            $url = $this->di['url']->link('invoice');
        } else {
            $invoiceUrl = $this->di['url']->link('invoice/' . $invoice->hash);
            if ((string) $invoice->status === \Model_Invoice::STATUS_PAID) {
                $url = $invoiceUrl;
            } else {
                $gatewayId = $order ? (int) ($order->gateway_id ?? 0) : 0;
                if ($gatewayId <= 0) {
                    try {
                        $gateway = $this->di['db']->findOne('PayGateway', 'gateway = ?', ['Blockonomics']);
                        $gatewayId = $gateway ? (int) $gateway->id : 0;
                    } catch (\Throwable $e) {
                        $this->di['logger']->info('Blockonomics finish gateway lookup failed: ' . $e->getMessage());
                    }
                }
                $url = $gatewayId > 0
                    ? $this->di['url']->link('invoice/banklink/' . $invoice->hash . '/' . $gatewayId)
                    : $invoiceUrl;
            }
        }

        return new \Symfony\Component\HttpFoundation\RedirectResponse($url);
    }

    private function monitorUsdtTxhash($order, string $txhash): void
    {
        try {
            $gateway = $this->di['db']->load('PayGateway', (int) $order->gateway_id);
            if (!$gateway || (int) $gateway->id <= 0) {
                return;
            }

            $adapter = $this->di['mod_service']('Invoice', 'PayGateway')->getPaymentAdapter($gateway);
            if (!$adapter || !method_exists($adapter, 'monitorToken')) {
                return;
            }

            $res = $adapter->monitorToken($txhash, 'USDT');
            if ((int) ($res['code'] ?? 0) !== 200) {
                $this->di['logger']->info('Blockonomics monitor_tx failed: HTTP ' . ($res['code'] ?? 0) . ' ' . substr((string) ($res['body'] ?? ''), 0, 200));
            }
        } catch (\Throwable $e) {
            $this->di['logger']->info('Blockonomics monitor_tx failed: ' . $e->getMessage());
        }
    }

    private function neutralPaymentStatus(): array
    {
        return [
            'pending' => false,
            'crypto' => null,
            'status' => null,
            'required' => 2,
            'submitted_only' => false,
            'stale' => false,
            'paid' => false,
            'payable' => true,
            'underpaid' => false,
            'reverted' => false,
            'order_id' => null,
        ];
    }
}
