<?php

declare(strict_types=1);

/**
 * Blockonomics payment adapter for FOSSBilling.
 *
 * Accept Bitcoin (BTC) or Tether (USDT, ERC-20) directly into your own wallet via
 * Blockonomics — non-custodial, no KYC, ~1% fee. One gateway: the buyer first picks a
 * coin, then sees an on-page address + amount (BTC also shows a QR; USDT asks the buyer
 * to submit their transaction hash). Payment is confirmed server-side via the Blockonomics
 * HTTP callback, routed to our companion module's CSRF-exempt guest endpoint and matched
 * back to the invoice by address (BTC) or txid (USDT).
 *
 * Ported from the official Blockonomics WHMCS plugin, following the structure of
 * FOSSBilling's bundled Stripe adapter.
 *
 * SPDX-License-Identifier: Apache-2.0
 */
class Payment_Adapter_Blockonomics implements FOSSBilling\InjectionAwareInterface
{
    protected ?Pimple\Container $di = null;

    private const string BASE_URL = 'https://www.blockonomics.co';
    private const string NEW_ADDRESS_URL = self::BASE_URL . '/api/new_address';
    private const string PRICE_URL = self::BASE_URL . '/api/price';

    /** Coins this gateway offers the buyer. */
    private const array SUPPORTED = ['BTC', 'USDT'];

    /** Blockonomics' marker txid for dashboard-generated test callbacks (no real BTC). */
    private const string TEST_TXID = 'WarningThisIsAGeneratedTestPaymentAndNotARealBitcoinTransaction';

    public function __construct(private $config)
    {
        if (empty($this->config['api_key'])) {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Blockonomics', ':missing' => 'API Key'], 4001);
        }
        if (empty($this->config['callback_secret'])) {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Blockonomics', ':missing' => 'Callback Secret'], 4001);
        }
    }

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => "Accept crypto payments — currently Bitcoin (BTC) and Tether (USDT, ERC-20) — directly to your own wallet via Blockonomics. Non-custodial, no KYC, ~1% fee. The buyer picks the coin at checkout.\n\n**Setup:** 1) Paste your Blockonomics API key and a random Callback Secret below and save. 2) In Blockonomics (Merchants → your store → HTTP Callback), register this exact URL: `https://YOUR-FOSSBILLING-DOMAIN/api/guest/blockonomics/callback?secret=YOUR-CALLBACK-SECRET` (use the same secret you entered below). Blockonomics requires the callback URL to exactly match a store, so this step is required.",
            'logo' => [
                'logo' => 'blockonomics.png',
                'height' => '30px',
                'width' => '30px',
            ],
            'form' => [
                'api_key' => [
                    'text', [
                        'label' => 'Blockonomics API Key',
                        'description' => 'Create one at Blockonomics → Merchants → API Keys.',
                    ],
                ],
                'callback_secret' => [
                    'text', [
                        'label' => 'Callback Secret',
                        'description' => 'A long random string (e.g. from a password generator) used to authenticate Blockonomics callbacks. Include it in the callback URL you register with Blockonomics (see above).',
                    ],
                ],
                'confirmations' => [
                    'select', [
                        'label' => 'Required confirmations before an invoice is marked paid',
                        'multiOptions' => [
                            '0' => '0 — instant (0-conf / mempool)',
                            '1' => '1 confirmation',
                            '2' => '2 confirmations (recommended)',
                        ],
                    ],
                ],
                'underpayment_slack' => [
                    'text', [
                        'label' => 'Underpayment tolerance (%)',
                        'description' => 'Payments up to this percentage below the expected amount are still accepted as full payment. Default 0.',
                        'required' => false,
                    ],
                ],
                'margin' => [
                    'text', [
                        'label' => 'Margin (%)',
                        'description' => 'Optional buffer added to the requested crypto amount to absorb price volatility between invoice display and payment. Default 0.',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * Step 1 of the buyer flow: render a coin chooser. Picking a coin calls the
     * blockonomics/checkout guest endpoint (which generates the address for just that coin
     * and returns its payment view), so we never call new_address for a coin the buyer
     * doesn't choose.
     */
    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $this->ensureInstalled();

        $invoice = $this->di['db']->getExistingModelById('Invoice', $invoice_id, 'Invoice not found');
        $hashJson = json_encode((string) $invoice->hash);
        $invoiceUrlJson = json_encode($this->di['tools']->url('invoice/' . $invoice->hash));
        $assetsJson = json_encode(SYSTEM_URL . 'modules/Blockonomics/assets');

        return <<<HTML
<div class="blockonomics-pay" style="max-width:480px;margin:0 auto;text-align:center">
    <p>Pay with Blockonomics — choose your cryptocurrency:</p>
    <div id="blk-chooser" style="display:flex;gap:.75em;justify-content:center;margin:1em 0">
        <button type="button" class="btn btn-outline-primary blk-coin" data-crypto="BTC" style="flex:1;max-width:180px;padding:1em">
            <strong>Bitcoin</strong><br><span class="text-muted">BTC</span>
        </button>
        <button type="button" class="btn btn-outline-primary blk-coin" data-crypto="USDT" style="flex:1;max-width:180px;padding:1em">
            <strong>Tether</strong><br><span class="text-muted">USDT (ERC-20)</span>
        </button>
    </div>
    <div id="blk-view"></div>
    <p id="blk-loading" style="display:none">Generating payment details…</p>
    <p id="blk-error" style="color:#b02a37"></p>
    <script>
    (function () {
        var INVOICE_HASH = {$hashJson};
        var INVOICE_URL = {$invoiceUrlJson};
        var ASSETS = {$assetsJson};
        var view = document.getElementById('blk-view');
        var loading = document.getElementById('blk-loading');
        var errEl = document.getElementById('blk-error');

        // Per-coin scripts, loaded only when that coin is picked. BTC: bundled QR lib (MIT)
        // + reconnecting-websocket for live payment detection. USDT: Blockonomics' hosted
        // web3-payment widget (it handles wallet connect, transfer and testmode itself).
        var DEPS = {
            BTC: [ASSETS + '/qrcode.min.js', ASSETS + '/reconnecting-websocket.min.js'],
            USDT: ['https://www.blockonomics.co/js/web3-payment.js']
        };

        function loadScript(src) {
            return new Promise(function (resolve, reject) {
                var s = document.querySelector('script[data-blk-src="' + src + '"]');
                if (s) { return s.dataset.loaded ? resolve() : (s.addEventListener('load', resolve), s.addEventListener('error', reject)); }
                s = document.createElement('script');
                s.src = src; s.setAttribute('data-blk-src', src);
                s.onload = function () { s.dataset.loaded = '1'; resolve(); };
                s.onerror = reject;
                document.head.appendChild(s);
            });
        }

        function post(endpoint, body) {
            return fetch('/api/guest/blockonomics/' + endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(body)
            }).then(function (r) { return r.json(); });
        }

        // After the coin's view HTML is injected: draw the BTC QR and open the live payment
        // websocket — Blockonomics pushes a message the moment the payment is seen, and the
        // page forwards to the invoice (mirrors the WHMCS plugin; ~2s lets the callback land).
        // USDT's <web3-payment> component activates by itself once its script is loaded.
        function activateView() {
            var qr = view.querySelector('#blockonomics-qr');
            if (qr && qr.getAttribute('data-uri') && window.QRCode) {
                qr.innerHTML = '';
                new QRCode(qr, { text: qr.getAttribute('data-uri'), width: 200, height: 200 });
            }
            var sockEl = view.querySelector('[data-socket-addr]');
            if (sockEl && window.ReconnectingWebSocket) {
                var ws = new ReconnectingWebSocket('wss://www.blockonomics.co/payment/' + sockEl.getAttribute('data-socket-addr'));
                ws.onmessage = function () {
                    ws.close();
                    var st = view.querySelector('#blk-pay-status');
                    if (st) { st.innerHTML = '<strong style="color:#198754">✓ Payment detected!</strong> Taking you to your invoice…'; }
                    setTimeout(function () { window.location = INVOICE_URL; }, 2000);
                };
            }
        }

        document.querySelectorAll('.blk-coin').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var crypto = btn.getAttribute('data-crypto');
                errEl.textContent = ''; view.innerHTML = ''; loading.style.display = '';
                document.getElementById('blk-chooser').style.display = 'none';
                Promise.all(DEPS[crypto].map(loadScript)).then(function () {
                    return post('checkout', { invoice_hash: INVOICE_HASH, crypto: crypto });
                }).then(function (j) {
                    loading.style.display = 'none';
                    if (j && typeof j.result === 'string') { view.innerHTML = j.result; activateView(); }
                    else {
                        document.getElementById('blk-chooser').style.display = '';
                        errEl.textContent = (j && j.error && j.error.message) || 'Could not start checkout. Please try again.';
                    }
                }).catch(function () {
                    loading.style.display = 'none';
                    document.getElementById('blk-chooser').style.display = '';
                    errEl.textContent = 'Network error. Please try again.';
                });
            });
        });
    })();
    </script>
</div>
HTML;
    }

    /**
     * Step 2 of the buyer flow (called by the blockonomics/checkout guest endpoint): generate
     * the receive address for the chosen coin, persist the order, and return that coin's
     * payment-view markup (no inline scripts — getHtml's parent script wires up behaviour).
     */
    public function renderCheckout(\Model_Invoice $invoice, string $crypto): string
    {
        $crypto = strtoupper($crypto);
        if (!in_array($crypto, self::SUPPORTED, true)) {
            throw new Payment_Exception('Unsupported cryptocurrency.');
        }

        $this->ensureInstalled();
        $this->ensureSchema();
        $invoiceService = $this->di['mod_service']('Invoice');
        $gatewayId = $this->resolveGatewayId();
        $confirmations = (int) ($this->config['confirmations'] ?? 2);
        $fiatTotal = $invoiceService->getTotalWithTax($invoice);

        // Reuse a not-yet-confirmed order for this invoice + coin so a refresh doesn't burn a
        // fresh address.
        $order = $this->getReusableOrder((int) $invoice->id, $crypto, $gatewayId, $confirmations);

        // Re-quote on revisit while no payment has been seen yet (status null): the price may
        // have moved since the address was issued, and a stale quote silently under/overpays
        // the merchant. Once a payment is detected (status >= 0) the quote is locked.
        if ($order && $order->status === null) {
            $price = $this->fetchPrice($invoice->currency, $crypto);
            $order->expected_satoshi = $this->convertFiatToUnits($fiatTotal, $price, $crypto);
            $order->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($order);
        }

        if (!$order) {
            $callbackUrl = $this->getCallbackUrl();              // constant; matches the store callback
            $price = $this->fetchPrice($invoice->currency, $crypto);
            $address = $this->fetchNewAddress($callbackUrl, $crypto); // NEVER pass reset=1
            $expectedUnits = $this->convertFiatToUnits($fiatTotal, $price, $crypto);

            $order = $this->di['db']->dispense('blockonomics_order');
            // USDT addresses are static (shared across invoices); append the invoice id so each
            // order has a unique key. BTC addresses are already unique per invoice.
            $order->addr = $crypto === 'USDT' ? ($address . '-' . $invoice->id) : $address;
            $order->txid = null;
            $order->invoice_id = (int) $invoice->id;
            $order->gateway_id = $gatewayId;
            $order->crypto = $crypto;
            $order->expected_satoshi = $expectedUnits; // smallest units: satoshis (BTC) or 1e-6 USDT
            $order->value_satoshi = 0;
            $order->status = null;
            $order->currency = $invoice->currency;
            $order->created_at = date('Y-m-d H:i:s');
            $order->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($order);
        }

        return $crypto === 'USDT'
            ? $this->renderUsdtView($invoice, $order, $confirmations, $fiatTotal)
            : $this->renderBtcView($invoice, $order, $confirmations, $fiatTotal);
    }

    /**
     * Handle the Blockonomics HTTP callback (via the companion module's guest endpoint →
     * createAndProcess). $data['get'] holds: secret, status (confirmation count), addr,
     * value (smallest units), txid. Crypto-agnostic: amounts are compared in smallest units.
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        $this->ensureInstalled();
        $this->ensureSchema();

        $get = $data['get'] ?? [];
        $secret = (string) ($get['secret'] ?? '');
        $status = isset($get['status']) ? (int) $get['status'] : -1;
        $addr = (string) ($get['addr'] ?? '');
        $value = isset($get['value']) ? (int) $get['value'] : 0;
        $txid = (string) ($get['txid'] ?? '');

        $logger = $this->di['logger'];

        // 1. Authenticate the callback.
        if (!hash_equals($this->getCallbackSecret(), $secret)) {
            $logger->info('Blockonomics callback rejected: secret mismatch (addr ' . $addr . ').');

            return;
        }

        // 2. Match our order by address (fallback by txid — this is how USDT resolves, since
        //    its address is shared, so the order is stored as "<addr>-<invoiceId>").
        $order = $this->di['db']->findOne('blockonomics_order', 'addr = ?', [$addr]);
        if (!$order && $txid !== '') {
            $order = $this->di['db']->findOne('blockonomics_order', 'txid = ?', [$txid]);
        }
        if (!$order) {
            $logger->info('Blockonomics callback: no matching order for addr ' . $addr . ', txid ' . $txid . '.');

            return;
        }

        $tx = $this->di['db']->getExistingModelById('Transaction', $id);
        $invoice = $this->di['db']->getExistingModelById('Invoice', $order->invoice_id);
        $invoiceService = $this->di['mod_service']('Invoice');
        $confirmations = (int) ($this->config['confirmations'] ?? 2);
        $expectedSatoshi = (int) $order->expected_satoshi;

        // Record what this callback reported on the order row.
        $order->txid = $txid;
        $order->status = $status;
        $order->value_satoshi = $value;
        $order->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($order);

        $tx->invoice_id = $invoice->id;
        $tx->currency = $invoice->currency;
        $tx->txn_status = (string) $status;

        // 3. Confirmation gate — not enough confirmations yet ⇒ pending, not paid.
        if ($status < $confirmations) {
            $tx->txn_id = $txid;
            $tx->status = Model_Transaction::STATUS_RECEIVED;
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            return;
        }

        // 4. Underpayment handling: within tolerance ⇒ count as full; otherwise credit the
        //    actual amount received (so partial payments record a partial credit, not full).
        $slackSatoshi = (float) ($this->config['underpayment_slack'] ?? 0) / 100 * $expectedSatoshi;
        if ($value < $expectedSatoshi - $slackSatoshi || $value > $expectedSatoshi) {
            $satoshiPaid = $value;
        } else {
            $satoshiPaid = $expectedSatoshi;
        }
        $percentPaid = $expectedSatoshi > 0 ? ($satoshiPaid / $expectedSatoshi * 100) : 0;
        $fiatTotal = $invoiceService->getTotalWithTax($invoice);
        $paymentAmount = round($percentPaid / 100 * $fiatTotal, 2);

        // 5. Build a unique transaction id (shared with the guest endpoint so the core's
        //    txn_id-based dedup in create() matches ours exactly).
        $uniqueTxid = self::uniqueTxnId($txid, $addr);
        $tx->txn_id = $uniqueTxid;

        // 6. Dedup: if a transaction with this unique id is already (being) processed, stop.
        $duplicate = $this->di['db']->findOne(
            'Transaction',
            'txn_id = ? AND status IN (?, ?) AND id != ?',
            [$uniqueTxid, Model_Transaction::STATUS_PROCESSED, Model_Transaction::STATUS_PROCESSING, $tx->id]
        );
        if ($duplicate) {
            $logger->info('Blockonomics callback: duplicate for ' . $uniqueTxid . ', ignoring.');
            $tx->status = Model_Transaction::STATUS_PROCESSED;
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            return;
        }

        // 7. Claim atomically (RECEIVED → PROCESSING) to guard against concurrent double-credit.
        $tx->status = Model_Transaction::STATUS_RECEIVED;
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);

        $transactionService = $this->di['mod_service']('Invoice', 'Transaction');
        if (!$transactionService->claimForProcessing((int) $tx->id)) {
            return;
        }

        // 8. Credit the client and settle the invoice from credits.
        $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);
        $clientService = $this->di['mod_service']('Client');
        $description = 'Blockonomics ' . ((string) ($order->crypto ?? '')) . ' payment ' . $uniqueTxid;
        $clientService->addFunds($client, $paymentAmount, $description, [
            'amount' => $paymentAmount,
            'description' => $description,
            'type' => 'transaction',
            'rel_id' => $tx->id,
        ]);

        if (!$invoiceService->isInvoiceTypeDeposit($invoice)) {
            if (!$invoice->approved) {
                $invoiceService->approveInvoice($invoice, ['use_credits' => false]);
            }
            $invoiceService->payInvoiceWithCredits($invoice);
        } else {
            $invoiceService->doBatchPayWithCredits(['client_id' => $client->id]);
        }

        $tx->amount = $paymentAmount;
        $tx->status = Model_Transaction::STATUS_PROCESSED;
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);

        $logger->info(sprintf(
            'Blockonomics: invoice %d credited %s %s (%s %d units, %d confirmations).',
            $invoice->id,
            number_format($paymentAmount, 2, '.', ''),
            $invoice->currency,
            (string) ($order->crypto ?? '?'),
            $satoshiPaid,
            $status
        ));
    }

    /**
     * Ask Blockonomics to monitor a USDT transaction the buyer submitted, so callbacks fire
     * for it. Mirrors the WHMCS plugin's monitor_tx call. Returns ['code','body'].
     *
     * @return array{code:int, body:string}
     */
    public function monitorToken(string $txhash, string $crypto): array
    {
        return $this->httpRequest(
            'POST',
            self::BASE_URL . '/api/monitor_tx',
            ['Authorization: Bearer ' . $this->config['api_key'], 'Content-Type: application/json'],
            json_encode(['txhash' => $txhash, 'crypto' => strtoupper($crypto), 'match_callback' => $this->getCallbackUrl()])
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function getCallbackSecret(): string
    {
        return (string) ($this->config['callback_secret'] ?? '');
    }

    /**
     * The unique transaction id for a callback. The same blockchain txid can fund multiple
     * addresses (e.g. one tx paying two invoices), so we append the address to keep it unique
     * per invoice. The dashboard test-payment marker is mapped to a per-address test id.
     * Public + static so the guest callback endpoint produces an identical key for dedup.
     */
    public static function uniqueTxnId(string $txid, string $addr): string
    {
        return $txid === self::TEST_TXID ? 'TEST-' . $addr : $txid . '-' . $addr;
    }

    /**
     * The constant callback URL Blockonomics calls. Points at our CSRF-exempt guest endpoint;
     * must exactly equal the store HTTP callback the merchant registers (Blockonomics
     * exact-matches match_callback against it), so it carries no per-invoice/per-coin params.
     */
    private function getCallbackUrl(): string
    {
        return SYSTEM_URL . 'api/guest/blockonomics/callback?secret=' . urlencode($this->getCallbackSecret());
    }

    /** Smallest-unit decimals: BTC = 8 (satoshis), USDT = 6. */
    private function getDecimals(string $crypto): int
    {
        return strtoupper($crypto) === 'USDT' ? 6 : 8;
    }

    /**
     * Self-install the pieces FOSSBilling's one-click installer can't place. The extension
     * zip is extracted into library/Payment/Adapter/Blockonomics/ only, but the callback and
     * checkout endpoints live in a companion module under /modules. On first use (and on
     * version upgrades) this mirrors the bundled module into place, activates it, and copies
     * the gateway logo — using the same filesystem paths the core installer writes to.
     */
    private function ensureInstalled(): void
    {
        $src = __DIR__ . DIRECTORY_SEPARATOR . 'module';
        if (!is_dir($src)) {
            return; // dev layout: module managed manually
        }

        $dest = PATH_MODS . DIRECTORY_SEPARATOR . 'Blockonomics';
        $srcVersion = (string) (json_decode((string) @file_get_contents($src . '/manifest.json'), true)['version'] ?? '0');
        $destVersion = (string) (json_decode((string) @file_get_contents($dest . '/manifest.json'), true)['version'] ?? null);

        if ($srcVersion === $destVersion && is_file(PATH_ROOT . '/public/gateways/blockonomics.png')) {
            $this->ensureModuleActivated($srcVersion);

            return;
        }

        try {
            $fs = new \Symfony\Component\Filesystem\Filesystem();
            $fs->mirror($src, $dest, null, ['override' => true]);

            $logo = __DIR__ . DIRECTORY_SEPARATOR . 'blockonomics.png';
            if (is_file($logo)) {
                $fs->copy($logo, PATH_ROOT . '/public/gateways/blockonomics.png', true);
            }
        } catch (\Exception $e) {
            throw new Payment_Exception('Blockonomics: could not install the companion module automatically (:err). Please copy the "module" folder from library/Payment/Adapter/Blockonomics to modules/Blockonomics manually.', [':err' => $e->getMessage()]);
        }

        $this->ensureModuleActivated($srcVersion);
    }

    /** Make sure the companion module is registered as an installed extension. */
    private function ensureModuleActivated(string $version): void
    {
        $ext = $this->di['db']->findOne('Extension', 'type = ? AND name = ?', ['mod', 'blockonomics']);
        if (!$ext) {
            $ext = $this->di['db']->dispense('Extension');
            $ext->type = 'mod';
            $ext->name = 'blockonomics';
        }
        if ($ext->status !== 'installed' || (string) $ext->version !== $version) {
            $ext->status = 'installed';
            $ext->version = $version;
            $this->di['db']->store($ext);
        }
    }

    /**
     * Create the order table if it does not exist. FOSSBilling runs RedBeanPHP in frozen mode
     * (no auto-schema), so we create it explicitly. All columns the adapter writes must exist.
     */
    private function ensureSchema(): void
    {
        $this->di['db']->exec(
            'CREATE TABLE IF NOT EXISTS blockonomics_order (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                addr VARCHAR(255) NULL,
                txid VARCHAR(255) NULL,
                invoice_id INT NULL,
                gateway_id INT NULL,
                crypto VARCHAR(16) NULL,
                expected_satoshi BIGINT NULL,
                value_satoshi BIGINT NULL,
                status INT NULL,
                currency VARCHAR(8) NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_blockonomics_addr (addr),
                KEY idx_blockonomics_invoice (invoice_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Reuse a not-yet-confirmed order for this invoice + coin + gateway, or null if a new one
     * must be generated. Filtering by crypto + gateway_id avoids reusing a different-coin order.
     */
    private function getReusableOrder(int $invoiceId, string $crypto, int $gatewayId, int $confirmations)
    {
        $order = $this->di['db']->findOne(
            'blockonomics_order',
            'invoice_id = ? AND crypto = ? AND gateway_id = ? ORDER BY id DESC',
            [$invoiceId, $crypto, $gatewayId]
        );
        if (!$order || empty($order->addr)) {
            return null;
        }
        if ($order->status === null || (int) $order->status < $confirmations) {
            return $order;
        }

        return null;
    }

    /**
     * Resolve THIS gateway instance's id. FOSSBilling injects it into the adapter config as
     * notify_url (`…/ipn.php?gateway_id=<id>`); fallback = match the PayGateway row whose
     * stored callback_secret equals ours.
     */
    private function resolveGatewayId(): int
    {
        $notify = (string) ($this->config['notify_url'] ?? '');
        if ($notify !== '') {
            parse_str((string) parse_url($notify, PHP_URL_QUERY), $params);
            if (!empty($params['gateway_id'])) {
                return (int) $params['gateway_id'];
            }
        }

        $secret = $this->getCallbackSecret();
        if ($secret !== '') {
            $rows = $this->di['db']->find('PayGateway', 'gateway = ?', ['Blockonomics']);
            foreach ($rows as $row) {
                $cfg = json_decode($row->config ?? '', true) ?: [];
                if (($cfg['callback_secret'] ?? null) === $secret) {
                    return (int) $row->id;
                }
            }
        }

        throw new Payment_Exception('Could not resolve the Blockonomics gateway instance.');
    }

    private function fetchPrice(string $currency, string $crypto): float
    {
        $res = $this->httpRequest('GET', self::PRICE_URL . '?' . http_build_query(['currency' => $currency, 'crypto' => strtoupper($crypto)]));
        $data = json_decode($res['body']);
        if ($res['code'] !== 200 || !isset($data->price) || !$data->price) {
            throw new Payment_Exception('Could not fetch the :crypto price from Blockonomics.', [':crypto' => strtoupper($crypto)]);
        }

        return (float) $data->price;
    }

    /**
     * Request a receive address, passing our constant callback URL as match_callback. For USDT
     * the returned address is static (shared across invoices). NEVER pass reset=1.
     */
    private function fetchNewAddress(string $matchCallback, string $crypto): string
    {
        $params = ['match_callback' => $matchCallback];
        if (strtoupper($crypto) === 'USDT') {
            $params['crypto'] = 'USDT';
        }
        $url = self::NEW_ADDRESS_URL . '?' . http_build_query($params);
        $res = $this->httpRequest('POST', $url, ['Authorization: Bearer ' . $this->config['api_key']]);
        $data = json_decode($res['body']);
        if ($res['code'] !== 200 || !isset($data->address) || !$data->address) {
            $msg = $data->error->message ?? ($data->message ?? ('HTTP ' . $res['code']));
            throw new Payment_Exception('Could not generate a :crypto address: :err', [':crypto' => strtoupper($crypto), ':err' => $msg]);
        }

        return (string) $data->address;
    }

    /**
     * Apply the optional merchant margin and convert a fiat amount to the crypto's smallest
     * units (satoshis for BTC, 1e-6 for USDT). A positive margin lowers the effective price.
     */
    private function convertFiatToUnits(float $fiat, float $price, string $crypto): int
    {
        $margin = (float) ($this->config['margin'] ?? 0);
        if ($margin > 0) {
            $price = $price * 100 / (100 + $margin);
        }

        return (int) (10 ** $this->getDecimals($crypto) * $fiat / $price);
    }

    /**
     * @return array{code:int, body:string}
     */
    private function httpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
        }

        $responseBody = curl_exec($ch);
        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Payment_Exception('Network error contacting Blockonomics: :err', [':err' => $err]);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $code, 'body' => (string) $responseBody];
    }

    /** BTC payment view markup (behaviour wired by getHtml's parent script). */
    private function renderBtcView(\Model_Invoice $invoice, $order, int $confirmations, float $fiatTotal): string
    {
        $btcAmount = rtrim(rtrim(sprintf('%.8f', ((int) $order->expected_satoshi) / 1.0e8), '0'), '.');
        $addr = (string) $order->addr;
        $uri = 'bitcoin:' . $addr . '?amount=' . $btcAmount;

        $addrHtml = htmlspecialchars($addr, ENT_QUOTES);
        $uriHtml = htmlspecialchars($uri, ENT_QUOTES);
        $amountHtml = htmlspecialchars($btcAmount, ENT_QUOTES);
        $fiatHtml = htmlspecialchars(number_format($fiatTotal, 2, '.', '') . ' ' . $invoice->currency, ENT_QUOTES);
        $confText = $confirmations === 0
            ? 'as soon as it is seen on the network'
            : 'after ' . $confirmations . ' confirmation' . ($confirmations === 1 ? '' : 's');

        return <<<HTML
<div style="text-align:center">
    <p>Send exactly <strong>{$amountHtml} BTC</strong> to the Bitcoin address below:</p>
    <div id="blockonomics-qr" data-uri="{$uriHtml}" data-socket-addr="{$addrHtml}" style="display:flex;justify-content:center;margin:1em 0"></div>
    <p style="word-break:break-all;font-family:monospace;background:#f5f5f5;padding:.6em;border-radius:6px">{$addrHtml}</p>
    <p>Invoice total: <strong>{$fiatHtml}</strong></p>
    <p><a class="btn btn-primary" href="{$uriHtml}">Open in Bitcoin wallet</a></p>
    <p id="blk-pay-status" class="text-muted" style="margin-top:1em">Waiting for payment — this page updates the moment your payment is detected. The invoice is marked paid {$confText}.</p>
</div>
HTML;
    }

    /**
     * USDT payment view: Blockonomics' hosted web3-payment web component handles the wallet
     * connection, the on-chain USDT transfer, capturing the transaction hash, and test-mode
     * simulation — then redirects to our `finish` endpoint with the txhash. The buyer types
     * nothing; test mode (auto-detected from the test address) mirrors the live flow exactly.
     */
    private function renderUsdtView(\Model_Invoice $invoice, $order, int $confirmations, float $fiatTotal): string
    {
        $receiveAddr = explode('-', (string) $order->addr)[0];
        $usdtAmount = rtrim(rtrim(sprintf('%.6f', ((int) $order->expected_satoshi) / 1.0e6), '0'), '.');
        $isTest = str_starts_with($receiveAddr, '0xTestUSDTAddress');
        $finishUrl = SYSTEM_URL . 'api/guest/blockonomics/finish?invoice_hash=' . urlencode((string) $invoice->hash);

        $addrAttr = htmlspecialchars($receiveAddr, ENT_QUOTES);
        $amountAttr = htmlspecialchars($usdtAmount, ENT_QUOTES);
        $finishAttr = htmlspecialchars($finishUrl, ENT_QUOTES);
        $fiatHtml = htmlspecialchars(number_format($fiatTotal, 2, '.', '') . ' ' . $invoice->currency, ENT_QUOTES);
        $testAttr = $isTest ? 'testmode="1"' : '';
        $testBanner = $isTest
            ? '<p style="background:#fff3cd;color:#664d03;padding:.5em;border-radius:6px"><strong>TEST MODE</strong> — no real USDT is required; the payment is simulated.</p>'
            : '';

        return <<<HTML
<div style="text-align:center">
    {$testBanner}
    <p>Pay <strong>{$amountAttr} USDT</strong> (ERC-20, Ethereum network) — invoice total <strong>{$fiatHtml}</strong>:</p>
    <web3-payment
        order_amount="{$amountAttr}"
        receive_address="{$addrAttr}"
        redirect_url="{$finishAttr}"
        {$testAttr}
    ></web3-payment>
    <p class="text-muted" style="margin-top:1em">Connect your wallet and pay — this invoice is marked paid automatically once the transaction confirms.</p>
</div>
HTML;
    }
}
