# Blockonomics for FOSSBilling

Accept crypto payments in FOSSBilling using Blockonomics. When a customer pays, the **crypto goes directly into your wallet**. No KYC. Currently supports Bitcoin (BTC) and Tether (USDT, ERC-20).

## Requirements

- FOSSBilling **0.8.3** or later (PHP 8.3+)
- Blockonomics API Key

## Configure Blockonomics

1. Create a [Blockonomics account](https://www.blockonomics.co/register) using email/password or Google. If using email/password, enter the activation code sent to your email.
2. Open [Dashboard → Wallets](https://www.blockonomics.co/dashboard#/wallet) and add the wallets you want to accept:
  - USDT: select USDT Wallet, enter a name, and paste your ERC-20 receiving address.
  - BTC: select BTC Wallet, enter a name and xPub. When prompted, paste a sample receiving address from the same wallet.
  Note: Never enter a seed phrase or private key, Blockonomics never asks you to.

3. Open [Dashboard → Stores](https://www.blockonomics.co/dashboard#/store), click Add a store, enter a name, enable BTC and/or USDT, and select the corresponding wallets. You can initially leave Callback URL empty.


## Install and connect FOSSBilling

4. Download `Blockonomics.zip` from the [latest release](https://github.com/anktd/blockonomics-fossbilling/releases/latest)
5. Extract the archive inside `/library/Payment/Adapter/` of your FOSSBilling installation. The archive creates the `Blockonomics/` folder for you

6. In FOSSBilling, open System → Payment Gateways → New Payment Gateway and click the cog beside Blockonomics.
7. Open the Blockonomics gateway settings and copy the generated Callback URL.
8. Return to [Blockonomics → Stores](https://www.blockonomics.co/dashboard#/store), paste the callback URL into your Store (added in step 3), and click Update Store.
9. Copy the API key from the [Blockonomics Stores page](https://www.blockonomics.co/dashboard#/store). Paste it into Blockonomics API Key in FOSSBilling, enable the gateway,  and click Update Gateway.
10. Hit **Test Setup**. Setup is complete when the enabled currencies display check marks. For ex. BTC ✅ USDT ✅

### Optional Integration Testing
Enable Testmode on the Blockonomics Store to simulate checkout and callback processing without making a real payment. The relevant test-mode control is the one on the Blockonomics Store. [Here](https://help.blockonomics.co/support/solutions/articles/33000287720-how-to-use-testmode-in-blockonomics) is a comprehensive guide about this.


## Support

- Issues and feature requests: [GitHub issues](https://github.com/anktd/blockonomics-fossbilling/issues)
- Reach out [here](https://help.blockonomics.co/support/home) for human support


## Licensing

This extension is open source software released under the [Apache 2.0 license](LICENSE).
