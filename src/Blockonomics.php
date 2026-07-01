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

    // No typed constants: FOSSBilling supports PHP 8.2, const types need 8.3.
    private const BASE_URL = 'https://www.blockonomics.co';
    private const NEW_ADDRESS_URL = self::BASE_URL . '/api/new_address';
    private const PRICE_URL = self::BASE_URL . '/api/price';
    private const WALLETS_URL = self::BASE_URL . '/api/v2/wallets';
    private const STORES_URL = self::BASE_URL . '/api/v2/stores?wallets=true';
    private const STORE_URL = self::BASE_URL . '/api/v2/stores';

    /** Coins this gateway offers the buyer. */
    private const SUPPORTED = ['BTC', 'USDT'];

    /** Confirmations required before an invoice is marked paid (fixed at 2). */
    private const CONFIRMATIONS = 2;

    /** Blockonomics' marker txid for dashboard-generated test callbacks (no real BTC). */
    private const TEST_TXID = 'WarningThisIsAGeneratedTestPaymentAndNotARealBitcoinTransaction';

    private static bool $moduleFilesEnsured = false;
    private static ?string $moduleFileInstallError = null;

    public function __construct(private $config)
    {
        if (empty($this->config['api_key'])) {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Blockonomics', ':missing' => 'API Key'], 4001);
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
        self::ensureModuleFiles();

        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => self::renderSetupDescription(),
            'logo' => [
                'logo' => 'blockonomics.png',
                'height' => '30px',
                'width' => '30px',
            ],
            'form' => [
                'api_key' => [
                    'text', [
                        'label' => 'Blockonomics API Key',
                        'description' => 'Get from Blockonomics → Merchants (Dashboard) → Stores.',
                    ],
                ],
            ],
        ];
    }

    private static function renderSetupDescription(): string
    {
        $manualNote = '';
        if (self::$moduleFileInstallError !== null) {
            $error = htmlspecialchars(self::$moduleFileInstallError, ENT_QUOTES);
            $manualNote = '<p class="blockonomics-setup-note blockonomics-setup-note-error">Automatic companion-module install failed: ' . $error . '. Copy the bundled module/ folder to modules/Blockonomics manually, then reload this page.</p>';
        }

        return <<<HTML
<div class="blockonomics-admin-setup" data-blockonomics-setup style="display:none;margin-top:12px">
    <style>
    .blockonomics-callback-row { display: flex; gap: 8px; align-items: stretch; margin-top: 4px; }
    .blockonomics-callback-row input { flex: 1 1 auto; min-width: 0; font-family: monospace; font-size: 12px; }
    .blockonomics-setup-results { margin-top: 10px; display: none; }
    .blockonomics-setup-results > div { margin: 3px 0; }
    .blockonomics-setup-results ul { margin: 4px 0 0 18px; padding: 0; }
    .blockonomics-setup-note { margin: 8px 0 0; }
    .blockonomics-setup-note-error { color: #d63939; }
    </style>
    <button type="button" class="btn btn-primary" data-blockonomics-test>Test Setup</button>
    <div class="blockonomics-setup-results" data-blockonomics-results aria-live="polite"></div>
    <label class="form-label" for="blockonomics-callback-url" style="margin:12px 0 0">Callback URL</label>
    <div class="blockonomics-callback-row">
        <input id="blockonomics-callback-url" class="form-control" data-blockonomics-callback readonly value="Loading callback URL..." autocomplete="off">
        <button type="button" class="btn btn-outline-secondary" data-blockonomics-copy>Copy</button>
    </div>
    <div class="form-hint">Your Blockonomics store's HTTP Callback — Test Setup registers it automatically.</div>
    {$manualNote}
    <script>
    (function () {
        var script = document.currentScript;
        var root = script ? script.closest('[data-blockonomics-setup]') : null;
        if (!root || root.dataset.initialized === '1') { return; }
        root.dataset.initialized = '1';

        function ready(fn) {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fn);
            } else {
                fn();
            }
        }

        ready(function () {
            var input = root.querySelector('[data-blockonomics-callback]');
            var copyButton = root.querySelector('[data-blockonomics-copy]');
            var testButton = root.querySelector('[data-blockonomics-test]');
            var results = root.querySelector('[data-blockonomics-results]');
            var shared = window.__blockonomicsSetup || (window.__blockonomicsSetup = {});

            function hasAdminApi() {
                return !!(window.FOSSBilling && window.FOSSBilling.api && window.FOSSBilling.api.admin && typeof window.FOSSBilling.api.admin.post === 'function');
            }

            function findApiKeyInput() {
                var selectors = [
                    'input[name="api_key"]',
                    'input[name="config[api_key]"]',
                    'input[name$="[api_key]"]',
                    'input[id*="api_key"]'
                ];
                for (var i = 0; i < selectors.length; i++) {
                    var found = document.querySelector(selectors[i]);
                    if (found) { return found; }
                }
                return null;
            }

            function isAdminGatewayPage() {
                return hasAdminApi() && /\/admin\b/.test(window.location.pathname) && !!findApiKeyInput();
            }

            if (!isAdminGatewayPage()) {
                if (root.parentNode) { root.parentNode.removeChild(root); }
                return;
            }

            // Move the panel out of the top description alert to sit directly below the API
            // key field, so setup reads top-to-bottom: API key -> Test Setup -> Callback URL.
            // If the DOM isn't as expected the panel just stays (and works) where it rendered.
            var alertHost = root.parentElement;
            var keyWrapper = findApiKeyInput().parentElement;
            if (keyWrapper && keyWrapper.parentElement) {
                keyWrapper.parentElement.insertBefore(root, keyWrapper.nextSibling);
                if (alertHost && alertHost.children.length === 0 && alertHost.textContent.trim() === '') {
                    alertHost.style.display = 'none';
                }
            }

            root.style.display = '';

            function clear(node) {
                while (node.firstChild) { node.removeChild(node.firstChild); }
            }

            function showMessage(message) {
                results.style.display = '';
                clear(results);
                var p = document.createElement('p');
                p.textContent = message;
                results.appendChild(p);
            }

            function appendSection(title, items) {
                if (!items || !items.length) { return; }
                var heading = document.createElement('strong');
                heading.textContent = title;
                results.appendChild(heading);
                var list = document.createElement('ul');
                items.forEach(function (item) {
                    var li = document.createElement('li');
                    li.textContent = String(item);
                    list.appendChild(li);
                });
                results.appendChild(list);
            }

            function unwrap(response) {
                if (response && response.error) {
                    var err = new Error(response.error.message || 'FossBilling API error');
                    err.payload = response.error;
                    throw err;
                }
                return response && Object.prototype.hasOwnProperty.call(response, 'result') ? response.result : response;
            }

            function errorCode(error) {
                var code = error && error.payload && error.payload.code ? error.payload.code : (error && error.code ? error.code : null);
                return code === null ? null : parseInt(code, 10);
            }

            function adminPost(endpoint, data) {
                // FossBilling's admin JS API is callback-style: post(endpoint, params, onSuccess, onError).
                // post() returns undefined (NOT a promise) and calls onSuccess with the already-unwrapped
                // result. Wrap it in a promise so the rest of this script can chain .then()/.catch()
                // without throwing synchronously (which would abort init before the buttons are wired).
                return new Promise(function (resolve, reject) {
                    var done = false;
                    function ok(result) { if (!done) { done = true; resolve(result); } }
                    function fail(error) { if (!done) { done = true; reject(error); } }
                    var ret;
                    try {
                        ret = window.FOSSBilling.api.admin.post(endpoint, data || {}, ok, fail);
                    } catch (e) {
                        fail(e);
                        return;
                    }
                    // Defensive: some builds may return a promise (raw envelope) instead of using callbacks.
                    if (ret && typeof ret.then === 'function') {
                        ret.then(function (response) {
                            try { ok(unwrap(response)); } catch (e) { fail(e); }
                        }, fail);
                    }
                });
            }

            function activateModule() {
                if (!shared.activatePromise) {
                    shared.activatePromise = adminPost('extension/activate', { type: 'mod', id: 'blockonomics' }).catch(function (error) {
                        shared.activatePromise = null;
                        throw error;
                    });
                }
                return shared.activatePromise;
            }

            function loadCallbackUrl() {
                if (!shared.callbackPromise) {
                    shared.callbackPromise = adminPost('blockonomics/callback_url', {}).catch(function (error) {
                        if (errorCode(error) === 715) {
                            return activateModule().then(function () {
                                return adminPost('blockonomics/callback_url', {});
                            });
                        }
                        throw error;
                    }).catch(function (error) {
                        shared.callbackPromise = null;
                        throw error;
                    });
                }
                return shared.callbackPromise;
            }

            function setCallbackUrl(data) {
                var url = data && data.callback_url ? data.callback_url : '';
                input.value = url || 'Could not load the Blockonomics callback URL.';
                copyButton.disabled = !url;
            }

            loadCallbackUrl().then(setCallbackUrl).catch(function (error) {
                copyButton.disabled = true;
                input.value = 'Could not load the Blockonomics callback URL.';
                if (errorCode(error) === 715) {
                    showMessage('Activate the Blockonomics module under Extensions, then reload this page.');
                } else {
                    showMessage('Could not initialize Blockonomics setup. The admin needs manage_extensions permission, or activate Blockonomics under Extensions.');
                }
            });

            copyButton.addEventListener('click', function () {
                var value = input.value;
                if (!value || copyButton.disabled) { return; }
                var copied = navigator.clipboard && navigator.clipboard.writeText
                    ? navigator.clipboard.writeText(value)
                    : new Promise(function (resolve) {
                        input.select();
                        document.execCommand('copy');
                        resolve();
                    });
                copied.then(function () { showMessage('Callback URL copied.'); });
            });

            function findGatewayId() {
                var candidates = [window.location.href];
                var nodes = document.querySelectorAll('input, textarea, a');
                Array.prototype.forEach.call(nodes, function (node) {
                    candidates.push(node.value || node.getAttribute('href') || node.textContent || '');
                });
                if (document.body) { candidates.push(document.body.textContent || ''); }
                for (var i = 0; i < candidates.length; i++) {
                    var match = String(candidates[i]).match(/[?&]gateway_id=(\d+)/);
                    if (match) { return parseInt(match[1], 10); }
                }
                return null;
            }

            function renderSetupResult(result) {
                results.style.display = '';
                clear(results);
                var message = document.createElement('div');
                message.style.fontWeight = '600';
                message.textContent = result && result.message ? String(result.message) : 'Test Setup complete.';
                results.appendChild(message);
                if (result && result.note) {
                    var note = document.createElement('div');
                    note.style.color = '#b26205';
                    note.textContent = String(result.note);
                    results.appendChild(note);
                }
                var cryptos = (result && result.cryptos) || [];
                if (result && result.store && result.store.name) {
                    var storeLine = document.createElement('div');
                    var storeLabel = document.createElement('span');
                    storeLabel.textContent = 'Store: ' + result.store.name + ' ';
                    storeLine.appendChild(storeLabel);
                    cryptos.forEach(function (c) {
                        var mark = document.createElement('span');
                        mark.textContent = String(c.code || '').toUpperCase() + ' ' + (c.ok ? '✔' : '✖');
                        mark.style.color = c.ok ? '#2fb344' : '#d63939';
                        mark.style.fontWeight = '600';
                        mark.style.marginRight = '10px';
                        mark.title = c.message ? String(c.message) : '';
                        storeLine.appendChild(mark);
                    });
                    results.appendChild(storeLine);
                }
                cryptos.forEach(function (c) {
                    if (!c.ok && c.message) {
                        var detail = document.createElement('div');
                        detail.style.color = '#d63939';
                        detail.textContent = String(c.code || '').toUpperCase() + ': ' + String(c.message);
                        results.appendChild(detail);
                    }
                });
                appendSection('Actions taken', result ? result.actions_taken : []);
                appendSection('Needs attention', result ? result.error : []);
                if (result && result.callback_url) {
                    input.value = result.callback_url;
                    copyButton.disabled = false;
                }
            }

            testButton.addEventListener('click', function () {
                var keyInput = findApiKeyInput();
                var apiKey = keyInput ? keyInput.value : '';
                testButton.disabled = true;
                showMessage('Testing Blockonomics setup...');
                adminPost('blockonomics/test_setup', {
                    gateway_id: findGatewayId(),
                    api_key: apiKey
                }).then(renderSetupResult).catch(function () {
                    showMessage('Test Setup failed before it could complete. Check that the Blockonomics module is active and your admin session is still valid.');
                }).then(function () {
                    testButton.disabled = false;
                });
            });
        });
    })();
    </script>
</div>
HTML;
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
        $apiBaseJson = json_encode(SYSTEM_URL . 'api/guest/blockonomics/');

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
        var API_BASE = {$apiBaseJson};
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
            return fetch(API_BASE + endpoint, {
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
        $confirmations = self::CONFIRMATIONS;
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
        $confirmations = self::CONFIRMATIONS;
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

        // 4. Payment-amount handling: an exact (or greater) payment counts as full; any
        //    shortfall is credited as the actual amount received (a partial credit, not full).
        if ($value !== $expectedSatoshi) {
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
        return self::deriveCallbackSecret((string) ($this->di['config']['salt'] ?? ''));
    }

    public static function deriveCallbackSecret(string $salt): string
    {
        // Truncated to 40 hex chars (160 bits) to keep the callback URL short — same secret
        // length our WooCommerce plugin uses (sha1). Truncated HMAC output is standard practice.
        return substr(hash_hmac('sha256', 'blockonomics:callback', $salt), 0, 40);
    }

    public static function getCallbackUrlFromDi(Pimple\Container $di): string
    {
        return self::buildCallbackUrl(self::deriveCallbackSecret((string) ($di['config']['salt'] ?? '')));
    }

    public static function buildCallbackUrl(string $secret): string
    {
        return SYSTEM_URL . 'api/guest/blockonomics/callback?secret=' . urlencode($secret);
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
        return self::buildCallbackUrl($this->getCallbackSecret());
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
        self::ensureModuleFiles();
        $srcVersion = self::getBundledModuleVersion();
        $this->ensureModuleActivated($srcVersion);
    }

    /**
     * Mirror bundled module files and the gateway logo into the install tree. Static getConfig()
     * runs without DI, so this method must never throw; manifest.json is copied last so an
     * interrupted install is retried on the next render.
     */
    public static function ensureModuleFiles(): bool
    {
        if (self::$moduleFilesEnsured) {
            return self::$moduleFileInstallError === null;
        }
        self::$moduleFilesEnsured = true;
        self::$moduleFileInstallError = null;

        if (!defined('PATH_MODS')) {
            return true;
        }

        $src = __DIR__ . DIRECTORY_SEPARATOR . 'module';
        if (!is_dir($src)) {
            return true; // dev layout: module managed manually
        }

        $dest = PATH_MODS . DIRECTORY_SEPARATOR . 'Blockonomics';
        $srcVersion = self::getBundledModuleVersion();
        $destVersion = (string) (json_decode((string) @file_get_contents($dest . '/manifest.json'), true)['version'] ?? null);
        $logoDest = defined('PATH_ROOT') ? PATH_ROOT . '/public/gateways/blockonomics.png' : null;

        if ($srcVersion === $destVersion && ($logoDest === null || is_file($logoDest))) {
            return true;
        }

        try {
            self::copyDirectoryWithoutManifest($src, $dest);

            $logo = __DIR__ . DIRECTORY_SEPARATOR . 'blockonomics.png';
            if ($logoDest !== null && is_file($logo)) {
                $logoDir = dirname($logoDest);
                if (!is_dir($logoDir) && !@mkdir($logoDir, 0775, true) && !is_dir($logoDir)) {
                    throw new \RuntimeException('Could not create ' . $logoDir);
                }
                if (!@copy($logo, $logoDest)) {
                    throw new \RuntimeException('Could not copy gateway logo');
                }
            }

            $manifestSrc = $src . DIRECTORY_SEPARATOR . 'manifest.json';
            if (is_file($manifestSrc) && !@copy($manifestSrc, $dest . DIRECTORY_SEPARATOR . 'manifest.json')) {
                throw new \RuntimeException('Could not copy module manifest');
            }
        } catch (\Throwable $e) {
            self::$moduleFileInstallError = $e->getMessage();

            return false;
        }

        return true;
    }

    private static function getBundledModuleVersion(): string
    {
        $src = __DIR__ . DIRECTORY_SEPARATOR . 'module' . DIRECTORY_SEPARATOR . 'manifest.json';

        return (string) (json_decode((string) @file_get_contents($src), true)['version'] ?? '0');
    }

    private static function copyDirectoryWithoutManifest(string $src, string $dest): void
    {
        if (!is_dir($dest) && !@mkdir($dest, 0775, true) && !is_dir($dest)) {
            throw new \RuntimeException('Could not create ' . $dest);
        }

        $items = scandir($src);
        if ($items === false) {
            throw new \RuntimeException('Could not read ' . $src);
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === 'manifest.json') {
                continue;
            }
            $from = $src . DIRECTORY_SEPARATOR . $item;
            $to = $dest . DIRECTORY_SEPARATOR . $item;
            if (is_dir($from)) {
                self::copyDirectoryWithoutManifest($from, $to);
            } elseif (is_file($from) && !@copy($from, $to)) {
                throw new \RuntimeException('Could not copy ' . $from);
            }
        }
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

    public function testSetup(): array
    {
        $callbackUrl = $this->getCallbackUrl();
        $result = [
            'message' => 'Blockonomics setup needs attention.',
            'success' => [],
            'error' => [],
            'store' => null,
            'cryptos' => [],
            'callback_url' => $callbackUrl,
            'actions_taken' => [],
        ];

        try {
            $walletResult = $this->validateApiKey();
            if (!$walletResult['ok']) {
                $result['error'][] = $walletResult['error'];

                return $result;
            }
            $wallets = $walletResult['wallets'];
            $result['success'][] = 'API key validated.';

            if (empty($wallets)) {
                $result['error'][] = 'No Blockonomics wallets were found. Create a wallet in Blockonomics, then run Test Setup again.';

                return $result;
            }

            $storeResult = $this->fetchStores();
            if (!$storeResult['ok']) {
                $result['error'][] = $storeResult['error'];

                return $result;
            }

            $classification = $this->classifyStores($storeResult['stores'], $callbackUrl);
            $store = $this->selectBestStore($classification['exact']);
            if ($store) {
                $result['success'][] = count($classification['exact']) > 1
                    ? 'Found multiple exact callback matches; selected the best configured store.'
                    : 'Found a store with the exact Blockonomics callback URL.';
            } elseif (!empty($classification['partial'])) {
                $store = $this->selectBestStore($classification['partial']);
                $updated = $this->updateStoreCallback($store, $callbackUrl);
                if (!$updated['ok']) {
                    $result['error'][] = $updated['error'];

                    return $result;
                }
                $store = $updated['store'];
                $result['actions_taken'][] = 'Updated a Blockonomics store callback URL to the FossBilling callback URL.';
            } else {
                $created = $this->createStore($callbackUrl);
                if (!$created['ok']) {
                    $result['error'][] = $created['error'];

                    return $result;
                }
                $store = $created['store'];
                $result['actions_taken'][] = 'Created a Blockonomics store for FossBilling.';
            }

            if (empty($store->wallets)) {
                if (count($wallets) === 1) {
                    $attached = $this->attachWallet($store, $wallets[0]);
                    if (!$attached['ok']) {
                        $result['error'][] = $attached['error'];

                        return $result;
                    }
                    $store = $attached['store'];
                    $result['actions_taken'][] = 'Attached the only available wallet to the Blockonomics store.';
                } else {
                    $result['error'][] = 'The selected store has no wallet attached, and this API key has multiple wallets. Attach the correct wallet in Blockonomics, then run Test Setup again.';
                    $result['store'] = $this->summarizeStore($store, []);

                    return $result;
                }
            }

            $enabledCryptos = $this->getEnabledCryptos($store);
            $result['store'] = $this->summarizeStore($store, $enabledCryptos);
            if (empty($enabledCryptos)) {
                $result['error'][] = 'No payment methods are enabled on the selected Blockonomics store.';

                return $result;
            }

            $result['cryptos'] = $this->testCryptos($enabledCryptos);
            if (empty($result['cryptos'])) {
                $result['error'][] = 'No BTC or USDT payment method is enabled on the selected Blockonomics store.';

                return $result;
            }

            if (empty($result['error'])) {
                $okCount = count(array_filter($result['cryptos'], static fn (array $c): bool => $c['ok']));
                if ($okCount === count($result['cryptos'])) {
                    $result['message'] = 'Blockonomics setup looks ready.';
                } elseif ($okCount > 0) {
                    $result['message'] = 'Blockonomics setup is working — some payment methods need attention.';
                }
            }
        } catch (\Throwable $e) {
            $result['error'][] = $e->getMessage();
        }

        return $result;
    }

    public function validateApiKey(): array
    {
        $res = $this->httpRequest('GET', self::WALLETS_URL, $this->apiHeaders());
        if ($res['code'] === 401) {
            return ['ok' => false, 'wallets' => [], 'error' => 'API key is incorrect.'];
        }
        if ($res['code'] !== 200) {
            return ['ok' => false, 'wallets' => [], 'error' => 'Could not verify the API key: ' . $this->apiErrorMessage($res)];
        }

        $data = json_decode($res['body']);
        if (!$data || !isset($data->data) || !is_array($data->data)) {
            return ['ok' => false, 'wallets' => [], 'error' => 'Invalid wallets response from Blockonomics.'];
        }

        return ['ok' => true, 'wallets' => $data->data, 'error' => null];
    }

    public function fetchStores(): array
    {
        $res = $this->httpRequest('GET', self::STORES_URL, $this->apiHeaders());
        if ($res['code'] === 401) {
            return ['ok' => false, 'stores' => [], 'error' => 'API key is incorrect.'];
        }
        if ($res['code'] !== 200) {
            return ['ok' => false, 'stores' => [], 'error' => 'Could not fetch Blockonomics stores: ' . $this->apiErrorMessage($res)];
        }

        $data = json_decode($res['body']);
        if (!$data || !isset($data->data) || !is_array($data->data)) {
            return ['ok' => false, 'stores' => [], 'error' => 'Invalid stores response from Blockonomics.'];
        }

        return ['ok' => true, 'stores' => $data->data, 'error' => null];
    }

    public function classifyStores(array $stores, string $callbackUrl): array
    {
        $matches = ['exact' => [], 'partial' => []];
        foreach ($stores as $store) {
            $storeCallback = (string) ($store->http_callback ?? '');
            if ($storeCallback === $callbackUrl) {
                $matches['exact'][] = $store;
            } elseif ($this->isSafePartialCallback($storeCallback, $callbackUrl)) {
                $matches['partial'][] = $store;
            }
        }

        return $matches;
    }

    public function findExactMatchingStore(array $stores, string $callbackUrl): ?object
    {
        return $this->selectBestStore($this->classifyStores($stores, $callbackUrl)['exact']);
    }

    public function selectBestStore(array $stores): ?object
    {
        if (empty($stores)) {
            return null;
        }

        $bestStore = $stores[0];
        $bestScore = $this->scoreStore($bestStore);
        foreach (array_slice($stores, 1) as $store) {
            $score = $this->scoreStore($store);
            if ($score > $bestScore) {
                $bestStore = $store;
                $bestScore = $score;
            }
        }

        return $bestStore;
    }

    public function scoreStore(object $store): int
    {
        $score = 0;
        if (!empty($store->wallets)) {
            $score += 10;
        }
        if (trim((string) ($store->name ?? '')) !== '') {
            $score++;
        }

        return $score;
    }

    public function createStore(string $callbackUrl): array
    {
        $res = $this->httpRequest(
            'POST',
            self::STORE_URL,
            $this->apiHeaders(),
            (string) json_encode(['name' => 'FOSSBilling Blockonomics', 'http_callback' => $callbackUrl])
        );
        if (!in_array($res['code'], [200, 201], true)) {
            return ['ok' => false, 'store' => null, 'error' => 'Could not create a Blockonomics store: ' . $this->apiErrorMessage($res)];
        }

        $store = $this->storeFromResponse($res);
        if (!$store) {
            return ['ok' => false, 'store' => null, 'error' => 'Blockonomics did not return the created store.'];
        }

        return ['ok' => true, 'store' => $store, 'error' => null];
    }

    public function updateStoreCallback(object $store, string $callbackUrl): array
    {
        $storeId = $this->storeId($store);
        if ($storeId === null) {
            return ['ok' => false, 'store' => $store, 'error' => 'Could not update the matching store because it has no id.'];
        }

        $res = $this->httpRequest(
            'POST',
            self::STORE_URL . '/' . rawurlencode((string) $storeId),
            $this->apiHeaders(),
            (string) json_encode([
                'name' => (string) ($store->name ?? 'FOSSBilling Blockonomics'),
                'http_callback' => $callbackUrl,
            ])
        );
        if ($res['code'] !== 200) {
            return ['ok' => false, 'store' => $store, 'error' => 'Could not update the Blockonomics store callback: ' . $this->apiErrorMessage($res)];
        }

        // Keep the original store object: it came from GET /stores?wallets=true and carries the
        // wallets array, which the update response does not. Just record the new callback on it.
        $store->http_callback = $callbackUrl;

        return ['ok' => true, 'store' => $store, 'error' => null];
    }

    public function attachWallet(object $store, object $wallet): array
    {
        $storeId = $this->storeId($store);
        $walletId = $this->walletId($wallet);
        if ($storeId === null || $walletId === null) {
            return ['ok' => false, 'store' => $store, 'error' => 'Could not attach the wallet because the store or wallet id is missing.'];
        }

        $res = $this->httpRequest(
            'POST',
            self::STORE_URL . '/' . rawurlencode((string) $storeId) . '/wallets',
            $this->apiHeaders(),
            (string) json_encode(['wallet_id' => $walletId])
        );
        if ($res['code'] !== 200) {
            return ['ok' => false, 'store' => $store, 'error' => 'Could not attach the wallet to the Blockonomics store: ' . $this->apiErrorMessage($res)];
        }

        $responseStore = $this->storeFromResponse($res);
        $updated = $responseStore && isset($responseStore->id) ? $responseStore : $store;
        $data = json_decode($res['body']);
        if (isset($data->data->wallets)) {
            $updated->wallets = $data->data->wallets;
        } elseif ($responseStore && isset($responseStore->wallets)) {
            $updated->wallets = $responseStore->wallets;
        }

        return ['ok' => true, 'store' => $updated, 'error' => null];
    }

    public function getEnabledCryptos(object $store): array
    {
        $enabled = [];
        if (!empty($store->wallets) && is_array($store->wallets)) {
            foreach ($store->wallets as $wallet) {
                if (!empty($wallet->crypto)) {
                    $crypto = strtolower((string) $wallet->crypto);
                    if (!in_array($crypto, $enabled, true)) {
                        $enabled[] = $crypto;
                    }
                }
            }
        }

        return $enabled;
    }

    /**
     * Probe address generation for each supported enabled crypto. Returns one entry per
     * testable coin: ['code' => 'btc', 'ok' => bool, 'message' => error text when not ok].
     */
    public function testCryptos(array $enabledCryptos): array
    {
        $results = [];
        $testable = array_values(array_intersect(['btc', 'usdt'], array_map('strtolower', $enabledCryptos)));

        foreach ($testable as $crypto) {
            try {
                $address = $this->fetchNewAddress($this->getCallbackUrl(), strtoupper($crypto));
                $results[] = ['code' => $crypto, 'ok' => $address !== '', 'message' => $address !== '' ? '' : 'Empty address returned.'];
            } catch (\Throwable $e) {
                $results[] = ['code' => $crypto, 'ok' => false, 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    private function apiHeaders(): array
    {
        return [
            'Authorization: Bearer ' . trim((string) $this->config['api_key']),
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    private function apiErrorMessage(array $res): string
    {
        $data = json_decode($res['body']);
        if (isset($data->error->message)) {
            return (string) $data->error->message;
        }
        if (isset($data->error) && is_string($data->error)) {
            return $data->error;
        }
        if (isset($data->message)) {
            return (string) $data->message;
        }

        return 'HTTP ' . $res['code'];
    }

    private function storeFromResponse(array $res): ?object
    {
        $data = json_decode($res['body']);
        if (!$data || !isset($data->data)) {
            return null;
        }
        if (is_array($data->data)) {
            return isset($data->data[0]) && is_object($data->data[0]) ? $data->data[0] : null;
        }
        if (is_object($data->data)) {
            return $data->data;
        }

        return null;
    }

    private function storeId(object $store): int|string|null
    {
        if (isset($store->id) && $store->id !== '') {
            return is_numeric($store->id) ? (int) $store->id : (string) $store->id;
        }

        return null;
    }

    private function walletId(object $wallet): ?int
    {
        if (isset($wallet->id) && is_numeric($wallet->id)) {
            return (int) $wallet->id;
        }

        return null;
    }

    private function isSafePartialCallback(string $storeCallback, string $callbackUrl): bool
    {
        if ($storeCallback === '' || $storeCallback === $callbackUrl) {
            return false;
        }

        $storeParts = parse_url($storeCallback);
        $targetParts = parse_url($callbackUrl);
        if (!is_array($storeParts) || !is_array($targetParts)) {
            return false;
        }
        if (($storeParts['path'] ?? '') !== '/api/guest/blockonomics/callback' || ($targetParts['path'] ?? '') !== '/api/guest/blockonomics/callback') {
            return false;
        }
        if (strtolower((string) ($storeParts['host'] ?? '')) !== strtolower((string) ($targetParts['host'] ?? ''))) {
            return false;
        }
        if ((string) ($storeParts['port'] ?? '') !== (string) ($targetParts['port'] ?? '')) {
            return false;
        }

        parse_str((string) ($storeParts['query'] ?? ''), $query);
        foreach (array_keys($query) as $key) {
            if ($key !== 'secret') {
                return false;
            }
        }

        return true;
    }

    private function summarizeStore(object $store, array $enabledCryptos): array
    {
        return [
            'id' => $this->storeId($store),
            'name' => (string) ($store->name ?? ''),
            'http_callback' => (string) ($store->http_callback ?? ''),
            'enabled_cryptos' => array_values($enabledCryptos),
            'wallet_count' => !empty($store->wallets) && is_array($store->wallets) ? count($store->wallets) : 0,
        ];
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
     * notify_url (`…/ipn.php?gateway_id=<id>`); fallback = the single Blockonomics gateway row.
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

        $row = $this->di['db']->findOne('PayGateway', 'gateway = ?', ['Blockonomics']);
        if ($row) {
            return (int) $row->id;
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
     * Convert a fiat amount to the crypto's smallest units (satoshis for BTC, 1e-6 for USDT).
     */
    private function convertFiatToUnits(float $fiat, float $price, string $crypto): int
    {
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
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 10,
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
