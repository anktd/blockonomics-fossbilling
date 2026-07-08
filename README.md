# Blockonomics for FOSSBilling

Accept **crypto payments** in [FOSSBilling](https://fossbilling.org) using [Blockonomics](https://www.blockonomics.co) — non-custodial, paid directly to your own wallet. No KYC, ~1% fee. Currently supports Bitcoin (BTC) and Tether (USDT, ERC-20).

## Features

- **BTC**: a fresh address per invoice, shown on-page with a QR code. Payments are detected live over a websocket and the invoice is marked paid automatically after 2 confirmations.
- **USDT (ERC-20)**: wallet-connect checkout powered by Blockonomics' payment widget — the buyer connects their wallet and pays; no transaction hashes to copy around.
- One gateway — the invoice-page tile shows the coins your store accepts (kept up to date by Test Setup), and the buyer picks the coin on an on-page payment card with QR, copyable address and amount, the live exchange rate, and confirmation progress.
- Safe by default: callbacks are authenticated with a secret, amounts are validated server-side, and duplicate callbacks can never double-credit an invoice.
- Full test mode: enable *Test Mode* on your Blockonomics store and the entire flow — including USDT wallet payments — runs with simulated coins.

## Requirements

- FOSSBilling **0.8** or later (PHP 8.3+)
- A free [Blockonomics](https://www.blockonomics.co) account with a store and a BTC wallet (and a USDT wallet if you want to accept USDT)

## Installation

### Extension directory (recommended)

Install it from the [FOSSBilling extension directory](https://extensions.fossbilling.org/extension/Blockonomics): in your admin panel, go to **System → Payment gateways → Install from the Extension Directory**, find Blockonomics and install it.

### Manual installation

1. Download `Blockonomics.zip` from the [latest release](https://github.com/anktd/blockonomics-fossbilling/releases/latest)
2. Create a folder named `Blockonomics` in `/library/Payment/Adapter` of your FOSSBilling installation
3. Extract the archive into that folder
4. Go to **System → Payment gateways**, find Blockonomics in the **New payment gateway** tab and click the cog icon to install it

The gateway ships with a small companion module (the public endpoints that receive Blockonomics payment callbacks). It installs itself automatically the first time the gateway is used — no extra steps.

## Uninstalling

For complete removal, uninstall the **Blockonomics payment gateway** under **System → Payment gateways**, then uninstall the **Blockonomics module** under **Extensions**. The gateway itself has no uninstall hook in FOSSBilling; the companion module cleanup removes the Blockonomics order table and mirrored gateway logo.

## Configuration

1. Open the Blockonomics gateway settings in your FOSSBilling admin panel (**System → Payment gateways → Blockonomics**).
2. **API Key** — get it from your [Blockonomics dashboard](https://www.blockonomics.co) under **Merchants (Dashboard) → Stores**, paste it here, and save.
3. Click **Test Setup**. It validates your API key and links your Blockonomics store automatically: the **Callback URL** shown below the button is registered as the store's HTTP Callback (creating a store if you have none), and address generation is tested for each enabled coin.
4. Prefer to do it manually? Copy the **Callback URL** from the settings page into your store's **HTTP Callback URL** field in the Blockonomics dashboard — it must match exactly, so paste it verbatim.
5. Enable the gateway — done.

## Testing

Enable **Test Mode** on your Blockonomics store. BTC checkouts then issue test addresses, and USDT checkouts use a simulated wallet with unlimited test funds — letting you verify the full payment lifecycle (checkout → payment → confirmations → invoice marked paid) without spending real coins.

## How it works

- At checkout the buyer picks BTC or USDT. The adapter fetches a receive address and the live exchange rate from the Blockonomics API and shows the payment screen on your invoice page — the buyer is never redirected off-site.
- Blockonomics sends a callback to your FOSSBilling instance for every status change of the payment. The extension validates the secret and the paid amount, tracks confirmations, and marks the invoice paid through FOSSBilling's standard transaction pipeline.
- A payment that matches (or exceeds) the requested amount marks the invoice paid; any shortfall is credited as a partial payment and the invoice stays unpaid until the balance is covered.

## Recovering a stuck USDT payment

USDT payments are matched to invoices by transaction hash, which is normally captured automatically when the buyer completes the payment widget. If a buyer paid but their invoice never updates — they closed the browser before the widget finished, or sent the USDT directly from an external wallet — the payment can be attached manually:

1. Ask the buyer for the **transaction hash** (`0x…`, 64 hex characters) of their USDT transfer.
2. Open the buyer's unpaid invoice and copy its **hash** from the invoice URL (`/invoice/<hash>`).
3. Visit (any browser, no login needed):

   ```
   https://your-fossbilling-domain/api/guest/blockonomics/finish?invoice_hash=<invoice hash>&txhash=<transaction hash>
   ```

This registers the transaction with Blockonomics for monitoring; the invoice is settled automatically once the confirmation callback arrives (already-confirmed transactions are picked up on the next monitoring pass). The endpoint is safe to re-open with the same values, and a hash that is already attached to another invoice is not re-attached. Note: this recovery requires that the USDT payment screen was opened at least once for the invoice (that step creates the payment record the hash attaches to) — if it never was, open the invoice's payment page and select USDT first.

## Licensing

This extension is open source software released under the [Apache 2.0 license](LICENSE). It bundles third-party components listed in [NOTICE](NOTICE).

## Support

- Issues and feature requests: [GitHub issues](https://github.com/anktd/blockonomics-fossbilling/issues)
- Blockonomics support: [blockonomics.freshdesk.com](https://blockonomics.freshdesk.com)
