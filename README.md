# Blockonomics for FOSSBilling

Accept crypto payments in [FOSSBilling](https://fossbilling.org) using [Blockonomics](https://www.blockonomics.co). When a customer pays, the crypto goes directly into your wallet. No KYC. Currently supports Bitcoin (BTC) and Tether (USDT, ERC-20).

## Requirements

- FOSSBilling **0.8** or later (PHP 8.3+)
- A free [Blockonomics](https://www.blockonomics.co) account

## Installing the plugin

1. Download `Blockonomics.zip` from the [latest release](https://github.com/anktd/blockonomics-fossbilling/releases/latest)
2. Create a folder named `Blockonomics` in `/library/Payment/Adapter` of your FOSSBilling installation
3. Extract the archive into that folder
4. Go to **System → Payment gateways**, find Blockonomics in the **New payment gateway** tab and click the cog icon to install it

## Setup Blockonomics merchant account

1. Get started using the [Blockonomics Quickstart guide](https://help.blockonomics.co/support/solutions/articles/33000315478-quickstart) — sign up, add your BTC and USDT wallets, and create a store using the **Callback URL** shown in the plugin settings
2. Paste your **API Key** into the plugin settings in FOSSBilling and save
3. Click **Test Setup**
4. Enable the gateway — done

## Licensing

This extension is open source software released under the [Apache 2.0 license](LICENSE). It bundles [qrcodejs](https://github.com/davidshimjs/qrcodejs) and [ReconnectingWebSocket](https://github.com/joewalnes/reconnecting-websocket), both MIT licensed.

## Support

- Issues and feature requests: [GitHub issues](https://github.com/anktd/blockonomics-fossbilling/issues)
- [Blockonomics Help Articles](https://help.blockonomics.co/support/home)
- Reach out [here](https://blockonomics.freshdesk.com/) for human support
