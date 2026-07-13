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

    /** Domain-separates the callback token from every other use of the install secret. */
    private const CALLBACK_SECRET_CONTEXT = 'blockonomics:callback:v1';

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
                // Square Blockonomics mark; the invoice page renders it as a background-image
                // at exactly these dimensions inside the core gateway tile.
                'logo' => 'blockonomics.png',
                'height' => '48px',
                'width' => '48px',
            ],
            'form' => [
                'api_key' => [
                    'text', [
                        'label' => 'Blockonomics API Key',
                        // Field descriptions render as Markdown (html_input=escape), so use MD link syntax.
                        'description' => 'Get from your [Blockonomics Dashboard](https://www.blockonomics.co/dashboard#/store).',
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
<div class="blockonomics-admin-setup" data-blockonomics-setup style="display:none;margin-top:16px">
    <style>
    .blockonomics-callback-row { display: flex; gap: 8px; align-items: stretch; margin-top: 4px; }
    .blockonomics-callback-row input { flex: 1 1 auto; min-width: 0; font-family: monospace; font-size: 12px; }
    .blockonomics-setup-results { margin-top: 10px; display: none; padding: .6em .8em; border-radius: 6px; background: rgba(98,105,118,.06); border-left: 3px solid #8a94a6; }
    .blockonomics-setup-results p { margin: 0; }
    .blockonomics-setup-results > div { margin: 3px 0; }
    .blockonomics-setup-note { margin: 8px 0 0; }
    .blockonomics-setup-note-error { color: #d63939; }
    </style>
    <button type="button" class="btn btn-primary" data-blockonomics-test>Test Setup</button>
    <div class="blockonomics-setup-results" data-blockonomics-results aria-live="polite"></div>
    <label class="form-label" for="blockonomics-callback-url" style="margin:16px 0 0">Callback URL</label>
    <div class="blockonomics-callback-row">
        <input id="blockonomics-callback-url" class="form-control" data-blockonomics-callback readonly value="Loading callback URL..." autocomplete="off">
        <button type="button" class="btn btn-outline-secondary" data-blockonomics-copy>Copy</button>
    </div>
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
                // No URL check: the admin path prefix is configurable (admin_area_prefix), so the
                // path is not a reliable signal. The admin JS API and the api_key input together
                // only exist on the admin gateway config page.
                return hasAdminApi() && !!findApiKeyInput();
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

            // The core "IPN Callback URL" (ipn.php) field is not used by this adapter — our
            // callback goes through the module's guest endpoint above — and having two
            // "callback URL" fields confuses setup. Hide the core one (its own grid column).
            try {
                var ipnInput = document.getElementById('gateway_callback');
                if (ipnInput && /ipn\.php/.test(ipnInput.value)) {
                    var ipnWrap = ipnInput.closest('.col-12') || ipnInput.parentElement;
                    if (ipnWrap) { ipnWrap.style.display = 'none'; }
                }
            } catch (e) { /* cosmetic only */ }

            function clear(node) {
                while (node.firstChild) { node.removeChild(node.firstChild); }
            }

            function showMessage(message) {
                // 'block', not '': clearing the inline style would fall back to the
                // stylesheet's display:none and render the feedback invisibly.
                results.style.display = 'block';
                results.style.borderLeftColor = '#8a94a6';
                clear(results);
                var p = document.createElement('p');
                p.textContent = message;
                results.appendChild(p);
            }

            // Render one message line, turning a single [label](https://www.blockonomics.co/…)
            // Markdown link into an anchor. The href group is pinned to blockonomics.co and
            // everything is built from text nodes — no HTML travels through this path.
            function renderErrorLine(el, message) {
                var m = String(message).match(/^(.*)\[([^\]]+)\]\((https:\/\/www\.blockonomics\.co\/[^\s)]*)\)(.*)$/);
                if (!m) { el.textContent = String(message); return; }
                el.appendChild(document.createTextNode(m[1]));
                var a = document.createElement('a');
                a.href = m[3]; a.target = '_blank'; a.rel = 'noopener';
                a.textContent = m[2];
                el.appendChild(a);
                el.appendChild(document.createTextNode(m[4]));
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
                copied.then(function () {
                    copyButton.textContent = 'Copied ✓';
                    copyButton.classList.add('btn-success');
                    copyButton.classList.remove('btn-outline-secondary');
                    if (copyButton.dataset.resetTimer) { clearTimeout(parseInt(copyButton.dataset.resetTimer, 10)); }
                    copyButton.dataset.resetTimer = String(setTimeout(function () {
                        copyButton.textContent = 'Copy';
                        copyButton.classList.remove('btn-success');
                        copyButton.classList.add('btn-outline-secondary');
                    }, 2000));
                });
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
                results.style.display = 'block';
                var errors = (result && result.error) || [];
                var cryptos = (result && result.cryptos) || [];
                var allOk = errors.length === 0 && cryptos.length > 0 && cryptos.every(function (c) { return c.ok; });
                results.style.borderLeftColor = errors.length ? '#d63939' : (allOk ? '#2fb344' : '#b26205');
                clear(results);
                if (cryptos.length) {
                    var storeLine = document.createElement('div');
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
                errors.forEach(function (message) {
                    var line = document.createElement('div');
                    renderErrorLine(line, message);
                    results.appendChild(line);
                });
                if (result && result.callback_url) {
                    input.value = result.callback_url;
                    copyButton.disabled = false;
                }
            }

            // Captured at page load; Test Setup only ever tests the SAVED key, so an edited
            // field must be saved first (Update Gateway reloads the page, re-capturing this).
            var savedApiKey = findApiKeyInput() ? findApiKeyInput().value : '';

            testButton.addEventListener('click', function () {
                var keyInput = findApiKeyInput();
                if (keyInput && keyInput.value !== savedApiKey) {
                    showMessage('Click Update Gateway to save settings and then hit Test Setup');
                    return;
                }
                testButton.disabled = true;
                showMessage('Testing Blockonomics setup...');
                adminPost('blockonomics/test_setup', {
                    gateway_id: findGatewayId()
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
        $this->ensureSchema();

        $invoice = $this->di['db']->getExistingModelById('Invoice', $invoice_id, 'Invoice not found');
        $existingOrder = $this->di['db']->findOne(
            'blockonomics_order',
            'invoice_id = ? AND status IS NOT NULL ORDER BY id DESC',
            [(int) $invoice->id]
        );
        $waitingHtml = $existingOrder && self::awaitingConfirmation($existingOrder)
            ? $this->renderWaitingView($invoice, $existingOrder)
            : '';
        $underpaidBanner = ($existingOrder
            && self::isUnderpaidOrder($existingOrder)
            && $invoice->status === Model_Invoice::STATUS_UNPAID)
                ? '<div class="blk-banner">Your previous crypto payment was below the required amount and did not settle this invoice. Contact the merchant regarding the earlier payment.</div>'
                : '';
        $revertedBanner = ($existingOrder
            && self::isRevertedOrder($existingOrder)
            && $invoice->status === Model_Invoice::STATUS_UNPAID)
                ? '<div class="blk-banner">Your previous transaction was reverted or dropped and did not settle this invoice. Select a payment method below to try again.</div>'
                : '';
        $chooserHidden = $waitingHtml !== '' ? 'style="display:none"' : '';
        $waitingTemplate = $this->renderWaitingView($invoice, null);
        $assetsUrl = htmlspecialchars(SYSTEM_URL . 'modules/Blockonomics/assets', ENT_QUOTES);
        $hashJson = json_encode((string) $invoice->hash);
        $invoiceUrlJson = json_encode($this->di['tools']->url('invoice/' . $invoice->hash));
        $assetsJson = json_encode(SYSTEM_URL . 'modules/Blockonomics/assets');
        $apiBaseJson = json_encode(SYSTEM_URL . 'api/guest/blockonomics/');


        return <<<HTML
<div class="blockonomics-pay" style="max-width:440px;margin:0 auto;text-align:center">
    <style>
    .blk-card{border:1px solid var(--bs-border-color,#e6e7e9);border-radius:12px;overflow:hidden;background:var(--bs-body-bg,#fff)}
    .blk-bar{display:flex;align-items:center;justify-content:center;gap:.55em;padding:.7em 1em;font-weight:600;font-size:.95em;background:#188433;color:#fff}
    .blk-bar[data-mode="neutral"]{background:var(--bs-tertiary-bg,#f2f4f6);color:var(--bs-body-color,#1d273b)}
    .blk-bar[data-mode="warn"]{background:#b26205;color:#fff}
    .blk-bar[data-mode="error"]{background:#d63939;color:#fff}
    .blk-spin{width:.9em;height:.9em;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:blkspin .9s linear infinite;flex:none;opacity:.9}
    @keyframes blkspin{to{transform:rotate(360deg)}}
    .blk-body{padding:1em 1.25em 1.15em}
    .blk-row{display:flex;justify-content:space-between;align-items:center;gap:1em;padding:.6em 0;border-bottom:1px solid var(--bs-border-color,#eceef0);font-size:.95em}
    .blk-muted{color:var(--bs-secondary-color,#68727f)}
    .blk-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
    .blk-label{display:block;text-align:left;font-size:.75em;letter-spacing:.04em;text-transform:uppercase;margin:1em 0 .3em;color:var(--bs-secondary-color,#68727f)}
    .blk-field{border:1px solid var(--bs-border-color,#e6e7e9);border-radius:8px;padding:.5em .6em;display:flex;align-items:center;gap:.5em;background:var(--bs-tertiary-bg,#f8f9fa)}
    .blk-field > span{flex:1;min-width:0;overflow-wrap:anywhere;text-align:left;font-size:.86em}
    .blk-copy{border:0;background:transparent;cursor:pointer;color:var(--bs-secondary-color,#68727f);padding:.3em;border-radius:6px;flex:none;line-height:0}
    .blk-copy:hover{background:var(--bs-border-color,#e6e7e9)}
    .blk-copy .blk-ic-check{display:none;color:#188433}
    .blk-copy.blk-copied .blk-ic-copy{display:none}
    .blk-copy.blk-copied .blk-ic-check{display:inline}
    .blk-qr{background:#fff;border:1px solid var(--bs-border-color,#e6e7e9);border-radius:10px;padding:12px;display:inline-block;line-height:0;margin:.9em auto .2em}
    #blk-chooser{display:flex;gap:.75em;justify-content:center;margin:.6em 0 .2em}
    .blk-coin{flex:1;max-width:170px;padding:.9em .5em;border:1px solid var(--bs-border-color,#dfe3e8);border-radius:10px;background:var(--bs-body-bg,#fff);cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:.35em;color:inherit;transition:border-color .15s,box-shadow .15s}
    .blk-coin:hover{border-color:#188433;box-shadow:0 1px 6px rgba(24,132,51,.18)}
    .blk-coin .blk-muted{font-size:.78em}
    .blk-note{font-size:.8em;margin:.8em 0 0}
    .blk-banner{background:#fff8e6;color:#664d03;padding:.65em .8em;border-radius:8px;margin:.4em 0 .8em;font-size:.88em;text-align:left}
    .blk-change{font-weight:400;font-size:.82em;margin-left:.5em}
    </style>
    <div class="blk-card">
        <div class="blk-bar" id="blk-bar" data-mode="neutral"><span class="blk-spin" id="blk-bar-spin" style="display:none"></span><span id="blk-bar-text">Select payment currency</span></div>
        <div class="blk-body">
            {$underpaidBanner}
            {$revertedBanner}
            <div id="blk-chooser" {$chooserHidden}>
                <button type="button" class="blk-coin" data-crypto="BTC">
                    <img src="{$assetsUrl}/btc.svg" width="38" height="38" alt="">
                    <span class="blk-muted">BTC</span>
                </button>
                <button type="button" class="blk-coin" data-crypto="USDT">
                    <img src="{$assetsUrl}/usdt.svg" width="38" height="38" alt="">
                    <span class="blk-muted">USDT · ERC-20</span>
                </button>
            </div>
            <div id="blk-view">{$waitingHtml}</div>
            <p id="blk-loading" class="blk-muted" style="display:none;margin:.8em 0 0">Generating payment details…</p>
            <p id="blk-error" style="margin:.5em 0 0"></p>
        </div>
    </div>
    <template id="blk-waiting-tpl">{$waitingTemplate}</template>
    <script>
    (function () {
        var INVOICE_HASH = {$hashJson};
        var INVOICE_URL = {$invoiceUrlJson};
        var ASSETS = {$assetsJson};
        var API_BASE = {$apiBaseJson};
        var view = document.getElementById('blk-view');
        var loading = document.getElementById('blk-loading');
        var errEl = document.getElementById('blk-error');
        var chooser = document.getElementById('blk-chooser');
        var bar = document.getElementById('blk-bar');
        var barText = document.getElementById('blk-bar-text');
        var barSpin = document.getElementById('blk-bar-spin');
        var payRoot = document.querySelector('.blockonomics-pay');
        var waitingPoll = null;
        var btcDetectPoll = null;

        // The banklink template renders an unconditional "Processing Payment... / Thank you for
        // your patience." card header above the gateway HTML; our card has its own status bar,
        // so drop the core one (this is the only card on the banklink page).
        (function () {
            var coreCard = payRoot ? payRoot.closest('.card') : null;
            var coreHeader = coreCard ? coreCard.querySelector(':scope > .card-header') : null;
            if (coreHeader) { coreHeader.remove(); }
        })();

        function setBar(mode, text, spin) {
            if (!bar) { return; }
            bar.setAttribute('data-mode', mode);
            barText.textContent = text;
            barSpin.style.display = spin ? '' : 'none';
        }

        function showChooser() {
            stopState(waitingPoll);
            stopState(btcDetectPoll);
            view.innerHTML = '';
            errEl.textContent = '';
            chooser.style.display = '';
            setBar('neutral', 'Select payment currency', false);
        }

        function showError(message) {
            errEl.textContent = String(message || '');
        }

        // Copy buttons ([data-copy]) and the "change" link, on markup injected later too.
        payRoot.addEventListener('click', function (e) {
            var change = e.target.closest('[data-blk-change]');
            if (change) {
                e.preventDefault();
                showChooser();
                return;
            }
            var btn = e.target.closest('.blk-copy');
            if (!btn) { return; }
            var value = btn.getAttribute('data-copy') || '';
            if (!value) { return; }
            var copied = navigator.clipboard && navigator.clipboard.writeText
                ? navigator.clipboard.writeText(value)
                : new Promise(function (resolve) {
                    var ta = document.createElement('textarea');
                    ta.value = value;
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); } catch (err) {}
                    document.body.removeChild(ta);
                    resolve();
                });
            copied.then(function () {
                btn.classList.add('blk-copied');
                setTimeout(function () { btn.classList.remove('blk-copied'); }, 1600);
            });
        });
        var DAY = 24 * 60 * 60 * 1000;
        var FIRST_WINDOW = 10 * 60 * 1000;
        var CONFIRMED_STALL = 3 * 60 * 1000;

        var TEXT = {
            DETECTED: 'Payment detected ✔. You can safely close this page, your invoice will be marked paid upon 2 confirmations.',
            USDT_SUBMITTED: 'Payment submitted ✔. Waiting for the network to verify your transaction…',
            USDT_STALLED: "We haven't been able to verify your transaction yet. If your wallet shows the transfer as failed or cancelled, you can start the payment again.",
            CONFIRMED_PAID: 'Payment confirmed ✔. Your invoice has been paid, taking you to your invoice…',
            UNDERPAID: 'Your payment confirmed, but it was below the required amount and did not settle this invoice. Contact the merchant regarding the earlier payment.',
            REVERTED: 'Your transaction was reverted or dropped and did not settle this invoice. Open the invoice to try again.',
            FINALIZING_STALLED: "Your payment is confirmed on the network, but the invoice hasn't updated yet. It's safe to close this page. If it isn't marked paid within a few minutes, please contact support with Txn ID ",
            NOT_PAYABLE: 'This invoice can no longer be paid.',
            CONNECTION: 'Connection issue — retrying…',
            STOPPED: 'Still pending — please check your invoice later.'
        };

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
                // Remove the tag on failure so a retry re-creates it instead of waiting
                // forever on a cached tag whose error event has already fired.
                s.onerror = function () { s.remove(); reject(new Error('script load failed: ' + src)); };
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

        function unwrap(response) {
            if (response && response.error) {
                throw new Error((response.error && response.error.message) || 'FossBilling API error');
            }
            return response && Object.prototype.hasOwnProperty.call(response, 'result') ? response.result : response;
        }

        function currentWaiting() {
            return view ? view.querySelector('[data-blk-waiting]') : null;
        }

        function setSubstatus(text) {
            var root = currentWaiting();
            var el = root ? root.querySelector('[data-blk-substatus]') : null;
            if (el) { el.textContent = text || ''; }
        }

        function clear(node) {
            while (node.firstChild) { node.removeChild(node.firstChild); }
        }

        function appendInvoiceLink(node) {
            node.appendChild(document.createTextNode(' '));
            var link = document.createElement('a');
            link.href = INVOICE_URL;
            link.textContent = 'View invoice';
            node.appendChild(link);
        }

        function setStatusText(text, withInvoiceLink) {
            var root = currentWaiting();
            var el = root ? root.querySelector('[data-blk-status]') : null;
            if (!el) { return; }
            clear(el);
            el.appendChild(document.createTextNode(text));
            if (withInvoiceLink) { appendInvoiceLink(el); }
        }

        function setSubmittedStalled() {
            var root = currentWaiting();
            var el = root ? root.querySelector('[data-blk-status]') : null;
            if (!el) { return; }
            clear(el);
            el.appendChild(document.createTextNode(TEXT.USDT_STALLED + ' '));
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-outline-primary';
            btn.textContent = 'Start payment again';
            btn.addEventListener('click', function () { window.location.reload(); });
            el.appendChild(btn);
        }

        function shortTxid(txid) {
            txid = String(txid || '');
            return txid.length > 20 ? txid.substring(0, 10) + '…' + txid.substring(txid.length - 6) : txid;
        }

        function stopState(state) {
            if (!state) { return; }
            state.active = false;
            if (state.timer) { clearTimeout(state.timer); }
        }

        function nextDelay(state) {
            if (state.intervalOverride) { return state.intervalOverride; }
            return Date.now() - state.startedAt < FIRST_WINDOW ? 10000 : 30000;
        }

        function scheduleWaiting(state, delay) {
            if (!state || !state.active) { return; }
            if (Date.now() - state.startedAt >= DAY) {
                setStatusText(TEXT.STOPPED, true);
                stopState(state);
                return;
            }
            if (state.timer) { clearTimeout(state.timer); }
            state.timer = setTimeout(function () { runWaitingPoll(state); }, delay);
        }

        function handlePaymentStatus(state, data) {
            var root = currentWaiting();
            if (!root || String(root.getAttribute('data-order-id') || '') !== String(state.orderId)) {
                stopState(state);
                return;
            }

            var required = parseInt(data && data.required, 10);
            required = required > 0 ? required : 2;
            var status = data && data.status !== null && typeof data.status !== 'undefined' ? parseInt(data.status, 10) : null;
            var crypto = data && data.crypto ? String(data.crypto) : (root.getAttribute('data-crypto') || '');
            if (crypto) { root.setAttribute('data-crypto', crypto); }

            if (data && data.paid) {
                setBar('paid', '✓ Payment confirmed', false);
                setStatusText(TEXT.CONFIRMED_PAID, false);
                stopState(state);
                setTimeout(function () { window.location = INVOICE_URL; }, 1500);
                return;
            }
            if (data && data.payable === false) {
                setBar('error', 'Invoice can no longer be paid', false);
                setStatusText(TEXT.NOT_PAYABLE, true);
                stopState(state);
                return;
            }
            if (data && data.underpaid) {
                setBar('warn', 'Payment received — amount was short', false);
                setStatusText(TEXT.UNDERPAID, true);
                stopState(state);
                return;
            }
            if (data && data.reverted) {
                setBar('error', 'Transaction reverted', false);
                setStatusText(TEXT.REVERTED, true);
                stopState(state);
                return;
            }
            if (data && data.submitted_only && data.stale) {
                setBar('warn', 'Transaction not verified yet', false);
                setSubmittedStalled();
                state.intervalOverride = 60000;
                scheduleWaiting(state, state.intervalOverride);
                return;
            }
            if (data && data.submitted_only) {
                setBar('await', 'Verifying your transaction…', true);
                setStatusText(TEXT.USDT_SUBMITTED, false);
                scheduleWaiting(state, nextDelay(state));
                return;
            }
            if (status === null || isNaN(status)) {
                scheduleWaiting(state, nextDelay(state));
                return;
            }
            if (status < required) {
                state.confirmedUnpaidSince = null;
                setBar('await', 'Confirming — ' + status + ' of ' + required, true);
                setStatusText(TEXT.DETECTED, false);
                scheduleWaiting(state, nextDelay(state));
                return;
            }

            if (!state.confirmedUnpaidSince) { state.confirmedUnpaidSince = Date.now(); }
            setBar('await', 'Finalizing…', true);
            setStatusText(TEXT.DETECTED, false);
            if (Date.now() - state.confirmedUnpaidSince >= CONFIRMED_STALL) {
                setStatusText(TEXT.FINALIZING_STALLED + (shortTxid(root.getAttribute('data-txid')) || 'unavailable') + '.', false);
            }
            scheduleWaiting(state, 30000);
        }

        function runWaitingPoll(state) {
            if (!state || !state.active) { return; }
            if (document.hidden) { return; }
            post('payment_status', { invoice_hash: INVOICE_HASH, order_id: state.orderId }).then(unwrap).then(function (data) {
                state.failures = 0;
                setSubstatus('');
                handlePaymentStatus(state, data || {});
            }).catch(function () {
                state.failures++;
                if (state.failures >= 3) { setSubstatus(TEXT.CONNECTION); }
                scheduleWaiting(state, 30000);
            });
        }

        function startWaitingPoll(orderId) {
            if (!orderId) { return; }
            stopState(waitingPoll);
            stopState(btcDetectPoll);
            waitingPoll = {
                active: true,
                orderId: String(orderId),
                startedAt: Date.now(),
                failures: 0,
                intervalOverride: null,
                confirmedUnpaidSince: null,
                timer: null
            };
            runWaitingPoll(waitingPoll);
        }

        function swapToWaiting(orderId, crypto, txid) {
            var tpl = document.getElementById('blk-waiting-tpl');
            if (!tpl || !view) { return null; }
            var fragment = tpl.content ? tpl.content.cloneNode(true) : document.createRange().createContextualFragment(tpl.innerHTML);
            var root = fragment.querySelector('[data-blk-waiting]');
            if (!root) { return null; }
            root.setAttribute('data-order-id', String(orderId || ''));
            root.setAttribute('data-crypto', crypto || 'BTC');
            if (txid) { root.setAttribute('data-txid', txid); }
            view.innerHTML = '';
            view.appendChild(fragment);
            return currentWaiting();
        }

        function startBtcDetectionPoll(orderId) {
            if (!orderId) { return; }
            stopState(btcDetectPoll);
            btcDetectPoll = { active: true, orderId: String(orderId), timer: null };
            function run() {
                if (!btcDetectPoll || !btcDetectPoll.active) { return; }
                if (btcDetectPoll.timer) { clearTimeout(btcDetectPoll.timer); }
                if (document.hidden) { return; }
                post('payment_status', { invoice_hash: INVOICE_HASH, order_id: orderId }).then(unwrap).then(function (data) {
                    if (data && data.status !== null && typeof data.status !== 'undefined') {
                        stopState(btcDetectPoll);
                        setBar('await', 'Payment detected — confirming…', true);
                        swapToWaiting(orderId, data.crypto || 'BTC', '');
                        startWaitingPoll(orderId);
                        return;
                    }
                    btcDetectPoll.timer = setTimeout(run, 30000);
                }).catch(function () {
                    btcDetectPoll.timer = setTimeout(run, 30000);
                });
            }
            btcDetectPoll.run = run;
            btcDetectPoll.timer = setTimeout(run, 30000);
        }

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { return; }
            if (waitingPoll && waitingPoll.active) { runWaitingPoll(waitingPoll); }
            if (btcDetectPoll && btcDetectPoll.active && typeof btcDetectPoll.run === 'function') { btcDetectPoll.run(); }
        });

        // After the coin's view HTML is injected: draw the BTC QR and open the live payment
        // websocket. Blockonomics pushes a message the moment the payment is seen; then this
        // page switches to the waiting state and polls our server until the invoice is paid.
        // USDT's <web3-payment> component activates by itself once its script is loaded.
        function activateView() {
            var waiting = currentWaiting();
            if (waiting) {
                chooser.style.display = 'none';
                setBar('await', waiting.getAttribute('data-submitted') === '1' ? 'Verifying your transaction…' : 'Payment detected — confirming…', true);
                startWaitingPoll(waiting.getAttribute('data-order-id'));
                return;
            }
            var qr = view.querySelector('#blockonomics-qr');
            if (qr && qr.getAttribute('data-uri') && window.QRCode) {
                qr.innerHTML = '';
                new QRCode(qr, { text: qr.getAttribute('data-uri'), width: 190, height: 190 });
            }
            if (view.querySelector('[data-order-id]')) {
                setBar('await', 'Awaiting payment…', true);
            }
            var sockEl = view.querySelector('[data-socket-addr]');
            var btcView = view.querySelector('[data-crypto="BTC"][data-order-id]');
            if (sockEl && window.ReconnectingWebSocket) {
                var ws = new ReconnectingWebSocket('wss://www.blockonomics.co/payment/' + sockEl.getAttribute('data-socket-addr'));
                ws.onmessage = function (event) {
                    ws.close();
                    var txid = '';
                    try {
                        var msg = JSON.parse(event && event.data ? event.data : '{}');
                        txid = msg && msg.txid ? String(msg.txid) : '';
                    } catch (e) {}
                    var orderId = btcView ? btcView.getAttribute('data-order-id') : '';
                    setBar('await', 'Payment detected — confirming…', true);
                    swapToWaiting(orderId, 'BTC', txid);
                    startWaitingPoll(orderId);
                };
            }
            if (btcView) {
                startBtcDetectionPoll(btcView.getAttribute('data-order-id'));
            }
        }

        document.querySelectorAll('.blk-coin').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var crypto = btn.getAttribute('data-crypto');
                errEl.textContent = ''; view.innerHTML = ''; loading.style.display = '';
                chooser.style.display = 'none';
                setBar('neutral', 'Preparing your payment…', true);
                Promise.all(DEPS[crypto].map(loadScript)).then(function () {
                    return post('checkout', { invoice_hash: INVOICE_HASH, crypto: crypto });
                }).then(function (j) {
                    loading.style.display = 'none';
                    if (j && typeof j.result === 'string') { view.innerHTML = j.result; activateView(); }
                    else {
                        setBar('neutral', 'Unable to start payment', false);
                        showError((j && j.error && j.error.message) || 'Could not start checkout. Please try again.');
                    }
                }).catch(function () {
                    loading.style.display = 'none';
                    setBar('neutral', 'Unable to start payment', false);
                    showError('Network error. Please try again.');
                });
            });
        });

        activateView();
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

        if ($order && self::awaitingConfirmation($order)) {
            $submittedOnly = strtoupper((string) ($order->crypto ?? '')) === 'USDT'
                && (int) $order->status === 0
                && (int) ($order->value_satoshi ?? 0) === 0
                && (string) ($order->txid ?? '') !== '';
            $updatedAt = strtotime((string) ($order->updated_at ?? ''));
            if ($submittedOnly && $updatedAt < time() - 2 * 60) {
                try {
                    $res = $this->monitorToken((string) $order->txid, 'USDT');
                    if ((int) ($res['code'] ?? 0) !== 200) {
                        $this->di['logger']->info('Blockonomics monitor_tx re-arm failed: HTTP ' . ($res['code'] ?? 0) . ' ' . substr((string) ($res['body'] ?? ''), 0, 200));
                    }
                } catch (\Throwable $e) {
                    $this->di['logger']->info('Blockonomics monitor_tx re-arm failed: ' . $e->getMessage());
                }
                $order->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($order);
            }

            return $this->renderWaitingView($invoice, $order);
        }

        // Re-quote only after 10 minutes; a revisit seconds after broadcast must not re-price
        // the order into a manufactured underpayment. Once detected (status >= 0), quote is locked.
        if ($order && $order->status === null && strtotime((string) $order->updated_at) < time() - 600) {
            $price = $this->fetchPrice($invoice->currency, $crypto);
            $order->expected_satoshi = $this->convertFiatToUnits($fiatTotal, $price, $crypto);
            $order->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($order);
        }

        if (!$order) {
            $callbackUrl = $this->getCallbackUrl();              // constant; matches the store callback
            $price = $this->fetchPrice($invoice->currency, $crypto);
            try {
                $address = $this->fetchNewAddress($callbackUrl, $crypto); // NEVER pass reset=1
            } catch (Payment_Exception $e) {
                // Likely a stale coin cache (wallet removed since the last Test Setup run) or a
                // transient API problem. Log the real error; show the buyer a friendly message.
                $this->di['logger']->info('Blockonomics: new_address failed for ' . $crypto . ' at checkout: ' . $e->getMessage());

                throw new Payment_Exception('Could not generate new address (this may be a temporary error, please try again). Note to webmaster: run Test Setup in your Blockonomics gateway settings to diagnose.');
            }
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
        $status = isset($get['status']) ? (int) $get['status'] : -999;
        $addr = (string) ($get['addr'] ?? '');
        $value = isset($get['value']) ? max(0, (int) $get['value']) : 0;
        $txid = (string) ($get['txid'] ?? '');

        $logger = $this->di['logger'];

        if ($status < -1) {
            $logger->info('Blockonomics callback rejected: missing or invalid status.');

            return;
        }

        // 1. Authenticate the callback.
        if (!hash_equals($this->getCallbackSecret(), $secret)) {
            $logger->info('Blockonomics callback rejected: secret mismatch (addr ' . $addr . ').');

            return;
        }

        // 2. Match our order. USDT's documented callback fields do not include the coin,
        //    so identify its pre-bound tx hash from our order row. BTC stays addr-first:
        //    one blockchain transaction can legitimately pay more than one BTC address.
        $txOrder = $txid !== '' ? $this->di['db']->findOne('blockonomics_order', 'txid = ?', [$txid]) : null;
        if ($txOrder && strtoupper((string) ($txOrder->crypto ?? '')) === 'USDT') {
            $order = $txOrder;
        } else {
            $order = $this->di['db']->findOne('blockonomics_order', 'addr = ?', [$addr]);
            $order = $order ?: $txOrder;
        }
        if (!$order) {
            $logger->info('Blockonomics callback: no matching order for addr ' . $addr . ', txid ' . $txid . '.');

            return;
        }

        $tx = $this->di['db']->getExistingModelById('Transaction', $id);
        if ($tx->status === Model_Transaction::STATUS_PROCESSED) {
            $logger->info('Blockonomics callback: transaction ' . $tx->id . ' already processed, ignoring re-dispatch.');

            return;
        }

        $invoice = $this->di['db']->getExistingModelById('Invoice', $order->invoice_id);
        $invoiceService = $this->di['mod_service']('Invoice');
        $confirmations = self::CONFIRMATIONS;
        $expectedSatoshi = (int) $order->expected_satoshi;

        // Record what this callback reported on the order row.
        $currentOrderStatus = $order->status === null ? null : (int) $order->status;
        if ($status === -1
            || (string) $order->txid !== $txid
            || ($currentOrderStatus !== -1 && $status >= (int) $currentOrderStatus)) {
            // Older-txid stale unconfirmed IPNs may cosmetically rewind this row; the next real callback self-heals and accounting uses transaction rows.
            $order->txid = $txid;
            $order->status = $status;
            $order->value_satoshi = $value;
            $order->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($order);
        }

        $tx->invoice_id = $invoice->id;
        $tx->currency = $invoice->currency;
        $tx->txn_status = (string) $status;
        $uniqueTxid = self::uniqueTxnId($txid, $addr);
        $tx->txn_id = $uniqueTxid;

        // 3. Confirmation gate — not enough confirmations yet ⇒ pending, not paid.
        if ($status >= 0 && $status < $confirmations) {
            $tx->status = Model_Transaction::STATUS_RECEIVED;
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            return;
        }

        // 4. Calculate the reported value. Amount sufficiency is decided in smallest units,
        //    before any client balance mutation. The full quote is required; a real shortfall
        //    never adds funds.
        $crypto = strtoupper((string) ($order->crypto ?? ''));
        $satoshiPaid = $value;
        $percentPaid = $expectedSatoshi > 0 ? ($satoshiPaid / $expectedSatoshi * 100) : 0;
        $fiatTotal = $invoiceService->getTotalWithTax($invoice);
        $paymentAmount = min(round($percentPaid / 100 * $fiatTotal, 2), round($fiatTotal, 2));
        $paymentSufficient = self::isPaymentSufficient($satoshiPaid, $expectedSatoshi);
        // Never turn an on-chain overpayment into customer wallet credit. Both coins settle
        // for the invoice total only; the actual crypto amount remains in the audit trail.
        $overpaid = $expectedSatoshi > 0 && $satoshiPaid > $expectedSatoshi;
        if ($overpaid) {
            $logger->info(sprintf('Blockonomics: %s overpayment on invoice %d — %d units above expected; credited invoice amount only (tx %s).', $crypto, $invoice->id, $satoshiPaid - $expectedSatoshi, $txid));
        } elseif ($crypto === 'USDT' && !$paymentSufficient) {
            $logger->info(sprintf('Blockonomics: USDT underpayment on invoice %d — received %d of %d expected units; verify the tx belongs to this invoice (tx %s).', $invoice->id, $satoshiPaid, $expectedSatoshi, $txid));
        }

        // 5. Dedup: if a transaction with this unique id is already (being) processed, stop.
        // Identical callbacks racing onto different rows could both pass this before either claims;
        // serializing that would require SELECT ... FOR UPDATE on the order row, but Blockonomics does not send identical callbacks milliseconds apart.
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

        // A retry of an already-recorded terminal failure needs no further work. FOSSBilling's
        // IPN-hash dedup returns the same error row for an identical callback.
        $existingError = (string) ($tx->error ?? '');
        if ($tx->status === Model_Transaction::STATUS_ERROR
            && (($status === -1 && str_starts_with($existingError, 'Blockonomics transaction reverted:'))
                || (!$paymentSufficient && str_starts_with($existingError, 'Blockonomics underpayment:')))) {
            return;
        }

        // 6. Claim atomically (RECEIVED → PROCESSING) to guard against concurrent double-credit.
        $transactionService = $this->di['mod_service']('Invoice', 'Transaction');
        if (!$transactionService->claimForProcessing((int) $tx->id)) {
            $logger->info('Blockonomics callback: transaction ' . $tx->id . ' claim refused, ignoring.');

            return;
        }

        $noteAddress = self::cleanPaymentIdentifier($crypto === 'USDT'
            ? explode('-', (string) $order->addr)[0]
            : (string) $order->addr);
        $noteTxid = self::cleanPaymentIdentifier($txid);
        $receivedCrypto = self::formatCryptoUnits($satoshiPaid, $crypto);
        $expectedCrypto = self::formatCryptoUnits($expectedSatoshi, $crypto);

        if ($status === -1) {
            $error = sprintf('Blockonomics transaction reverted: %s transaction %s was reverted or dropped.', $crypto ?: 'crypto', $noteTxid);
            $tx->amount = 0;
            $tx->status = Model_Transaction::STATUS_ERROR;
            $tx->error = $error;
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);
            $invoiceService->addNote($invoice, sprintf(
                'Blockonomics %s transaction reverted or dropped: %s to %s (tx %s). Invoice remains unpaid.',
                $crypto,
                $receivedCrypto,
                $noteAddress,
                $noteTxid
            ));
            $logger->info('Blockonomics: ' . $error);

            return;
        }

        if (!$paymentSufficient) {
            $error = sprintf(
                'Blockonomics underpayment: expected %s %s, received %s %s.',
                $expectedCrypto,
                $crypto,
                $receivedCrypto,
                $crypto
            );
            $tx->amount = $paymentAmount;
            $tx->status = Model_Transaction::STATUS_ERROR;
            $tx->error = $error;
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);
            $invoiceService->addNote($invoice, sprintf(
                'Blockonomics underpayment: received %s %s of %s %s expected to %s (tx %s). Invoice remains unpaid; no client credit was added.',
                $receivedCrypto,
                $crypto,
                $expectedCrypto,
                $crypto,
                $noteAddress,
                $noteTxid
            ));
            $logger->info(sprintf('Blockonomics: invoice %d underpaid; no client credit added (%s).', $invoice->id, $uniqueTxid));

            return;
        }

        // 7. Credit the client and settle the invoice from credits.
        $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);
        $clientService = $this->di['mod_service']('Client');
        $description = sprintf('Blockonomics %s payment to %s (tx %s)', $crypto, $noteAddress, $noteTxid);
        $existingCredit = $this->di['db']->findOne(
            'ClientBalance',
            'type = ? AND rel_id = ?',
            ['transaction', $tx->id]
        );
        if ($existingCredit) {
            $logger->info('Blockonomics callback: client credit for transaction ' . $tx->id . ' already exists, skipping duplicate credit.');
        } else {
            $clientService->addFunds($client, $paymentAmount, $description, [
                'amount' => $paymentAmount,
                'description' => $description,
                'type' => 'transaction',
                'rel_id' => $tx->id,
            ]);
        }

        // Settle only invoices that are still payable. A refunded/canceled invoice (e.g. a
        // stuck tx confirming after the merchant refunded) must not be flipped back to paid
        // and have its activation tasks re-run; the funds stay as the account credit above.
        if ($invoice->status !== Model_Invoice::STATUS_UNPAID) {
            $logger->info('Blockonomics callback: invoice ' . $invoice->id . ' is ' . $invoice->status . '; funds kept as client credit, settlement skipped.');
        } elseif (!$invoiceService->isInvoiceTypeDeposit($invoice)) {
            if (!$invoice->approved) {
                $invoiceService->approveInvoice($invoice, ['use_credits' => false]);
            }
            $invoiceService->payInvoiceWithCredits($invoice);
        } else {
            // Deposit ("Add funds") invoice: the addFunds above IS the deposit — the invoice's
            // deposit item ships pre-charged and its executeTask is a no-op, so marking the
            // invoice paid moves no further money (same pattern as the core Stripe adapter).
            // Batch-paying other invoices here would silently divert the top-up (verified live:
            // the deposit stayed unpaid while an unrelated invoice got settled).
            $invoiceService->markAsPaid($invoice);
        }

        if ($overpaid) {
            $invoiceService->addNote($invoice, sprintf(
                'Blockonomics overpayment: received %s %s; expected %s %s to %s (tx %s). Invoice credited for the invoice total only.',
                $receivedCrypto,
                $crypto,
                $expectedCrypto,
                $crypto,
                $noteAddress,
                $noteTxid
            ));
        } else {
            $invoiceService->addNote($invoice, sprintf(
                'Blockonomics payment received: %s %s to %s (tx %s).',
                $receivedCrypto,
                $crypto,
                $noteAddress,
                $noteTxid
            ));
        }

        $tx->amount = $paymentAmount;
        $tx->status = Model_Transaction::STATUS_PROCESSED;
        $tx->error = null;
        $tx->error_code = null;
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
        return self::getCallbackSecretFromConfig();
    }

    public static function deriveCallbackSecret(string $salt): string
    {
        if (strlen(trim($salt)) < 32) {
            throw new Payment_Exception('FOSSBilling installation salt is missing or invalid.');
        }

        // Truncated to 40 hex chars (160 bits) to keep the callback URL short — same secret
        // length our WooCommerce plugin uses (sha1). Truncated HMAC output is standard practice.
        return substr(hash_hmac('sha256', self::CALLBACK_SECRET_CONTEXT, $salt), 0, 40);
    }

    /** Derive one stable per-install callback token without persisting another secret. */
    public static function getCallbackSecretFromConfig(): string
    {
        $salt = \FOSSBilling\Config::getProperty('info.salt');

        return self::deriveCallbackSecret(is_string($salt) ? $salt : '');
    }

    public static function getCallbackUrlFromConfig(): string
    {
        return self::buildCallbackUrl(self::getCallbackSecretFromConfig());
    }

    /** @deprecated Kept so an older mirrored admin module still loads during the upgrade. */
    public static function getCallbackUrlFromDi(Pimple\Container $di): string
    {
        return self::getCallbackUrlFromConfig();
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

    public static function isValidUsdtTxhash(string $h): bool
    {
        if (preg_match('/^0x[0-9a-fA-F]{64}$/', $h) === 1) {
            return true;
        }

        // Blockonomics' test-mode widget generates txhashes of the form
        // TestUSDTTxid_<units>_<random>; their server recognizes the prefix on monitor_tx
        // and simulates the confirming callbacks. On a live store the prefix is inert
        // (no callback is ever sent), so accepting it grants nothing.
        return preg_match('/^TestUSDTTxid_\d+_[A-Za-z0-9]+$/', $h) === 1;
    }

    /** Blockonomics store-testmode addresses (BTC: 1TestBTCAddress…, USDT: 0xTestUSDTAddress…). */
    public static function isTestAddress(string $addr): bool
    {
        return str_starts_with($addr, '1TestBTCAddress') || str_starts_with($addr, '0xTestUSDTAddress');
    }

    public static function isStaleUnconfirmed($order): bool
    {
        $status = $order->status ?? null;
        if ($status === null || (int) $status !== 0) {
            return false;
        }

        $updatedAt = strtotime((string) ($order->updated_at ?? ''));
        if ($updatedAt === false) {
            return false;
        }

        $crypto = strtoupper((string) ($order->crypto ?? ''));
        $value = (int) ($order->value_satoshi ?? 0);
        $txid = (string) ($order->txid ?? '');
        $usdtSubmittedStale = $crypto === 'USDT'
            && $value === 0
            && $txid !== ''
            && $updatedAt < time() - 15 * 60;
        $droppedTxStale = $updatedAt < time() - 4 * 60 * 60;

        return $usdtSubmittedStale || $droppedTxStale;
    }

    public static function awaitingConfirmation($order): bool
    {
        $status = $order->status ?? null;

        return $status !== null
            && (int) $status >= 0
            && (int) $status < self::CONFIRMATIONS
            && !self::isStaleUnconfirmed($order);
    }

    public static function isUnderpaidOrder($order): bool
    {
        $status = (int) ($order->status ?? 0);
        $value = (int) ($order->value_satoshi ?? 0);
        $expected = (int) ($order->expected_satoshi ?? 0);

        return $status >= self::CONFIRMATIONS
            && $value > 0
            && $value < $expected;
    }

    public static function isRevertedOrder($order): bool
    {
        return $order->status !== null && (int) $order->status === -1;
    }

    /** Decide in smallest units so fiat rounding can never accept a real shortfall. */
    public static function isPaymentSufficient(int $received, int $expected): bool
    {
        if ($received <= 0 || $expected <= 0) {
            return false;
        }

        return $received >= $expected;
    }

    private static function formatCryptoUnits(int $units, string $crypto): string
    {
        $decimals = strtoupper($crypto) === 'USDT' ? 6 : 8;

        return rtrim(rtrim(number_format($units / (10 ** $decimals), $decimals, '.', ''), '0'), '.');
    }

    private static function cleanPaymentIdentifier(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9:_-]/', '', $value) ?? '';
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

    /**
     * Validate the merchant's Blockonomics setup. No auto-fixing: the store must already
     * exist with the exact callback URL and a wallet attached — otherwise a single plain
     * message tells the merchant what to do.
     */
    public function testSetup(): array
    {
        $callbackUrl = $this->getCallbackUrl();
        $result = ['error' => [], 'cryptos' => [], 'callback_url' => $callbackUrl];

        try {
            $walletResult = $this->validateApiKey();
            if (!$walletResult['ok']) {
                $result['error'][] = $walletResult['error'];

                return $result;
            }
            if (empty($walletResult['wallets'])) {
                $result['error'][] = 'Please add a [wallet](https://www.blockonomics.co/dashboard#/wallet)';

                return $result;
            }

            $storeResult = $this->fetchStores();
            if (!$storeResult['ok']) {
                $result['error'][] = $storeResult['error'];

                return $result;
            }

            $store = $this->findExactMatchingStore($storeResult['stores'], $callbackUrl);
            if (!$store) {
                $result['error'][] = 'Please add a Store on [Blockonomics Dashboard](https://www.blockonomics.co/dashboard#/store) with the callback URL shown below';

                return $result;
            }

            $enabledCryptos = $this->getEnabledCryptos($store);
            if (empty($enabledCryptos)) {
                $result['error'][] = 'Please attach a [wallet](https://www.blockonomics.co/dashboard#/store) to your store';

                return $result;
            }

            $result['cryptos'] = $this->testCryptos($enabledCryptos);
        } catch (\Throwable $e) {
            $result['error'][] = $e->getMessage();
        }

        return $result;
    }

    public function validateApiKey(): array
    {
        $res = $this->httpRequest('GET', self::WALLETS_URL, $this->apiHeaders());
        if ($res['code'] === 401) {
            return ['ok' => false, 'wallets' => [], 'error' => 'API Key is incorrect.'];
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
            return ['ok' => false, 'stores' => [], 'error' => 'API Key is incorrect.'];
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

    public function findExactMatchingStore(array $stores, string $callbackUrl): ?object
    {
        $exact = array_values(array_filter(
            $stores,
            static fn ($store): bool => (string) ($store->http_callback ?? '') === $callbackUrl
        ));

        return $this->selectBestStore($exact);
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
        if (self::isTestAddress((string) $order->addr) && $order->status === null) {
            // Store-testmode era leftovers: once testmode is off, a reused test address would
            // trap the buyer in a simulated checkout. Regenerating costs nothing real (testmode
            // on ⇒ new_address returns another test address; off ⇒ a real one).
            return null;
        }
        if (self::isStaleUnconfirmed($order)) {
            // A restart after a stalled payment gets a fresh row; BTC may burn one extra address.
            return null;
        }
        if ($order->status === null || ((int) $order->status >= 0 && (int) $order->status < $confirmations)) {
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

    private function renderWaitingView(\Model_Invoice $invoice, $order): string
    {
        $crypto = $order ? strtoupper((string) ($order->crypto ?? 'BTC')) : 'BTC';
        if (!in_array($crypto, self::SUPPORTED, true)) {
            $crypto = 'BTC';
        }

        $orderId = $order ? (int) $order->id : 0;
        $status = $order && $order->status !== null ? (int) $order->status : 0;
        $txid = $order ? (string) ($order->txid ?? '') : '';
        $value = $order ? (int) ($order->value_satoshi ?? 0) : 0;
        $submittedOnly = $crypto === 'USDT' && $status === 0 && $value === 0 && $txid !== '';
        $txAttr = htmlspecialchars($txid, ENT_QUOTES);
        $cryptoAttr = htmlspecialchars($crypto, ENT_QUOTES);
        $invoiceUrl = htmlspecialchars($this->di['tools']->url('invoice/' . $invoice->hash), ENT_QUOTES);

        // Status copy shared with the JS TEXT constants — keep them in sync.
        $statusHtml = $submittedOnly
            ? 'Payment submitted ✔. Waiting for the network to verify your transaction…'
            : 'Payment detected ✔. You can safely close this page, your invoice will be marked paid upon 2 confirmations.';

        $referenceHtml = '';
        if ($txid !== '') {
            $shortTxid = strlen($txid) > 20 ? substr($txid, 0, 10) . '…' . substr($txid, -6) : $txid;
            $referenceHtml = '<p class="blk-muted" style="overflow-wrap:anywhere;font-size:.85em;margin:.5em 0 0">Txn ID: <span data-blk-ref class="blk-mono">' . htmlspecialchars($shortTxid, ENT_QUOTES) . '</span></p>';
        }

        $submittedAttr = $submittedOnly ? ' data-submitted="1"' : '';

        return <<<HTML
<div data-blk-waiting data-order-id="{$orderId}" data-crypto="{$cryptoAttr}" data-txid="{$txAttr}"{$submittedAttr} style="text-align:center">
    <p data-blk-status style="margin:.9em 0 0">{$statusHtml}</p>
    {$referenceHtml}
    <p data-blk-substatus class="blk-muted" style="margin-top:.75em"></p>
    <p style="margin:.9em 0 0"><a href="{$invoiceUrl}">View invoice</a></p>
</div>
HTML;
    }

    /** The copy-button icon pair (copy + confirmation check, toggled by the .blk-copied class). */
    private static function copyIconSvg(): string
    {
        return '<svg class="blk-ic-copy" width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><rect x="4" y="4" width="8" height="8" rx="1.5"/><path d="M2 10V3.5A1.5 1.5 0 0 1 3.5 2H10"/></svg>'
            . '<svg class="blk-ic-check" width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M2.5 7.5 5.5 10.5 11.5 3.5"/></svg>';
    }

    /** BTC payment view markup (behaviour wired by getHtml's parent script). */
    private function renderBtcView(\Model_Invoice $invoice, $order, int $confirmations, float $fiatTotal): string
    {
        $btcAmount = rtrim(rtrim(sprintf('%.8f', ((int) $order->expected_satoshi) / 1.0e8), '0'), '.');
        $addr = (string) $order->addr;
        $uri = 'bitcoin:' . $addr . '?amount=' . $btcAmount;
        $rate = (float) $btcAmount > 0 ? $fiatTotal / (float) $btcAmount : 0.0;

        $addrHtml = htmlspecialchars($addr, ENT_QUOTES);
        $uriHtml = htmlspecialchars($uri, ENT_QUOTES);
        $amountHtml = htmlspecialchars($btcAmount, ENT_QUOTES);
        $fiatHtml = htmlspecialchars(number_format($fiatTotal, 2) . ' ' . $invoice->currency, ENT_QUOTES);
        $rateHtml = htmlspecialchars('1 BTC = ' . number_format($rate, 2) . ' ' . $invoice->currency, ENT_QUOTES);
        $assetsUrl = htmlspecialchars(SYSTEM_URL . 'modules/Blockonomics/assets', ENT_QUOTES);
        $confText = $confirmations === 0
            ? 'as soon as it is seen on the network'
            : 'after ' . $confirmations . ' confirmation' . ($confirmations === 1 ? '' : 's');
        $orderId = (int) $order->id;
        $copyIcon = self::copyIconSvg();

        return <<<HTML
<div data-order-id="{$orderId}" data-crypto="BTC" style="text-align:center">
    <div class="blk-row">
        <span class="blk-muted">Pay with</span>
        <span style="display:flex;align-items:center;gap:.45em;font-weight:600"><img src="{$assetsUrl}/btc.svg" width="20" height="20" alt="">Bitcoin<a href="#" data-blk-change class="blk-muted blk-change">change</a></span>
    </div>
    <div class="blk-row" style="border-bottom:0">
        <span class="blk-muted">Amount</span>
        <span style="text-align:right">
            <span style="display:inline-flex;align-items:center;gap:.2em"><span class="blk-mono" style="font-weight:600">{$amountHtml} BTC</span><button type="button" class="blk-copy" data-copy="{$amountHtml}" title="Copy amount">{$copyIcon}</button></span><br>
            <span class="blk-muted" style="font-size:.8em">{$fiatHtml} · {$rateHtml}</span>
        </span>
    </div>
    <div class="blk-qr"><div id="blockonomics-qr" data-uri="{$uriHtml}" data-socket-addr="{$addrHtml}"></div></div>
    <span class="blk-label">Bitcoin address</span>
    <div class="blk-field"><span class="blk-mono">{$addrHtml}</span><button type="button" class="blk-copy" data-copy="{$addrHtml}" title="Copy address">{$copyIcon}</button></div>
    <a class="btn btn-primary" href="{$uriHtml}" style="width:100%;margin-top:1em">Open in wallet</a>
    <p class="blk-note blk-muted">The invoice is marked paid {$confText}.</p>
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
        $fiatHtml = htmlspecialchars(number_format($fiatTotal, 2) . ' ' . $invoice->currency, ENT_QUOTES);
        $assetsUrl = htmlspecialchars(SYSTEM_URL . 'modules/Blockonomics/assets', ENT_QUOTES);
        $testAttr = $isTest ? 'testmode="1"' : '';
        $orderId = (int) $order->id;
        $copyIcon = self::copyIconSvg();

        return <<<HTML
<div data-order-id="{$orderId}" data-crypto="USDT" style="text-align:center">
    <div class="blk-row">
        <span class="blk-muted">Pay with</span>
        <span style="display:flex;align-items:center;gap:.45em;font-weight:600"><img src="{$assetsUrl}/usdt.svg" width="20" height="20" alt="">Tether<a href="#" data-blk-change class="blk-muted blk-change">change</a></span>
    </div>
    <div class="blk-row" style="border-bottom:0">
        <span class="blk-muted">Amount</span>
        <span style="text-align:right">
            <span style="display:inline-flex;align-items:center;gap:.2em"><span class="blk-mono" style="font-weight:600">{$amountAttr} USDT</span><button type="button" class="blk-copy" data-copy="{$amountAttr}" title="Copy amount">{$copyIcon}</button></span><br>
            <span class="blk-muted" style="font-size:.8em">{$fiatHtml} · ERC-20, Ethereum network</span>
        </span>
    </div>
    <div style="margin-top:1em">
        <web3-payment
            order_amount="{$amountAttr}"
            receive_address="{$addrAttr}"
            redirect_url="{$finishAttr}"
            {$testAttr}
        ></web3-payment>
    </div>
</div>
HTML;
    }
}
