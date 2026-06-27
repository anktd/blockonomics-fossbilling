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
// Composer's classmap. When PHP instantiates this class it must resolve the parent
// \Api_Abstract, which isn't reliably autoloadable in that context — load it explicitly from the
// core library first (mirrors the adapter-include pattern used in callback() below).
if (!class_exists('Api_Abstract', false)) {
    require_once PATH_LIBRARY . '/Api/Abstract.php';
}

class Guest extends \Api_Abstract
{
    public function callback($data)
    {
        $data = is_array($data) ? $data : [];
        $addr = isset($data['addr']) ? (string) $data['addr'] : '';
        $txid = isset($data['txid']) ? (string) $data['txid'] : '';

        if ($addr === '' && $txid === '') {
            return ['result' => 'ignored', 'reason' => 'no addr or txid'];
        }

        // Resolve the order the adapter created in getHtml(), keyed by address (fallback txid).
        $order = $this->di['db']->findOne('blockonomics_order', 'addr = ?', [$addr]);
        if (!$order && $txid !== '') {
            $order = $this->di['db']->findOne('blockonomics_order', 'txid = ?', [$txid]);
        }
        if (!$order) {
            $this->di['logger']->info('Blockonomics callback: no matching order for addr ' . $addr . ', txid ' . $txid . '.');

            return ['result' => 'ignored', 'reason' => 'no matching order'];
        }

        // Verify the secret (defence in depth). Derived from the instance salt -- the same
        // value the adapter embeds in the registered callback URL.
        $expectedSecret = hash_hmac('sha256', 'blockonomics:callback', (string) ($this->di['config']['salt'] ?? ''));
        $providedSecret = (string) ($data['secret'] ?? '');
        if (!hash_equals($expectedSecret, $providedSecret)) {
            $this->di['logger']->info('Blockonomics callback rejected: secret mismatch (addr ' . $addr . ').');

            return ['result' => 'ignored', 'reason' => 'secret mismatch'];
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

        // Hand off to the core transaction pipeline with the resolved invoice_id. This creates
        // the Transaction and calls Payment_Adapter_Blockonomics::processTransaction(), which
        // does the confirmation gate, underpayment + dedup handling, and marks the invoice paid.
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

        $invoice = $invoiceHash !== '' ? $this->di['db']->findOne('Invoice', 'hash = ?', [$invoiceHash]) : null;

        if ($invoice && $txhash !== '') {
            $order = $this->di['db']->findOne('blockonomics_order', 'invoice_id = ? AND crypto = ? ORDER BY id DESC', [(int) $invoice->id, 'USDT']);
            // Skip if this txhash is already tied to a different order (dedup).
            $other = $order ? $this->di['db']->findOne('blockonomics_order', 'txid = ? AND id != ?', [$txhash, (int) $order->id]) : null;

            if ($order && !$other && (string) $order->txid !== $txhash) {
                $order->txid = $txhash;
                if ($order->status === null) {
                    $order->status = 0;
                }
                $order->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($order);

                $gateway = $this->di['db']->load('PayGateway', (int) $order->gateway_id);
                $adapter = $gateway ? $this->di['mod_service']('Invoice', 'PayGateway')->getPaymentAdapter($gateway) : null;
                if ($adapter && method_exists($adapter, 'monitorToken')) {
                    $res = $adapter->monitorToken($txhash, (string) $order->crypto);
                    if ((int) ($res['code'] ?? 0) !== 200) {
                        $this->di['logger']->info('Blockonomics monitor_tx failed: HTTP ' . ($res['code'] ?? 0) . ' ' . substr((string) ($res['body'] ?? ''), 0, 200));
                    }
                }
            }
        }

        // Send the buyer back to the invoice; it flips to paid once the callback arrives.
        $url = $invoice
            ? $this->di['url']->link('invoice/' . $invoice->hash)
            : $this->di['url']->link('invoice');

        return new \Symfony\Component\HttpFoundation\RedirectResponse($url);
    }
}
