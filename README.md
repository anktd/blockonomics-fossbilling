# Blockonomics for FOSSBilling

Accept **crypto payments** in [FOSSBilling](https://fossbilling.org) using [Blockonomics](https://www.blockonomics.co) — non-custodial, paid directly to your own wallet. No KYC, ~1% fee. Currently supports Bitcoin (BTC) and Tether (USDT, ERC-20).

## Features

- **BTC**: a fresh address per invoice, shown on-page with a QR code. Payments are detected live over a websocket and the invoice is marked paid automatically after 2 confirmations.
- **USDT (ERC-20)**: wallet-connect checkout powered by Blockonomics' payment widget — the buyer connects their wallet and pays; no transaction hashes to copy around.
- One gateway, the buyer picks the coin at checkout.
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

## Configuration

1. Open the Blockonomics gateway settings in your FOSSBilling admin panel (**System → Payment gateways → Blockonomics**).
2. **API Key** — get it from your [Blockonomics dashboard](https://www.blockonomics.co) under **Merchants (Dashboard) → Stores**, paste it here, and save.
3. Copy the **Callback URL** shown on the settings page — it's generated automatically, with no secret for you to set. (If it's blank, open any invoice's Blockonomics payment page once, then reload the settings page.)
4. In your Blockonomics dashboard, open your store's settings and paste that URL into the **HTTP Callback URL** field. Blockonomics matches it exactly, so paste it verbatim.
5. Enable the gateway — done.

## Testing

Enable **Test Mode** on your Blockonomics store. BTC checkouts then issue test addresses, and USDT checkouts use a simulated wallet with unlimited test funds — letting you verify the full payment lifecycle (checkout → payment → confirmations → invoice marked paid) without spending real coins.

## How it works

- At checkout the buyer picks BTC or USDT. The adapter fetches a receive address and the live exchange rate from the Blockonomics API and shows the payment screen on your invoice page — the buyer is never redirected off-site.
- Blockonomics sends a callback to your FOSSBilling instance for every status change of the payment. The extension validates the secret and the paid amount, tracks confirmations, and marks the invoice paid through FOSSBilling's standard transaction pipeline.
- A payment that matches (or exceeds) the requested amount marks the invoice paid; any shortfall is credited as a partial payment and the invoice stays unpaid until the balance is covered.

## Licensing

This extension is open source software released under the [Apache 2.0 license](LICENSE). It bundles third-party components listed in [NOTICE](NOTICE).

## Support

- Issues and feature requests: [GitHub issues](https://github.com/anktd/blockonomics-fossbilling/issues)
- Blockonomics support: [blockonomics.freshdesk.com](https://blockonomics.freshdesk.com)
