<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure helpers in Payment_Adapter_Blockonomics: callback-secret
 * derivation, callback-URL building, transaction-id dedup keys, and the store
 * classification used by Test Setup. No network, DI, or database involved.
 */
final class BlockonomicsAdapterTest extends TestCase
{
    private const CALLBACK = 'https://shop.example/api/guest/blockonomics/callback?secret=aaaa';

    private function adapter(): Payment_Adapter_Blockonomics
    {
        return new Payment_Adapter_Blockonomics(['api_key' => 'test-key']);
    }

    private function store(string $callback, array $extra = []): object
    {
        $store = (object) $extra;
        $store->http_callback = $callback;

        return $store;
    }

    private function order(array $extra = []): object
    {
        return (object) array_merge([
            'crypto' => 'BTC',
            'status' => null,
            'value_satoshi' => '0',
            'expected_satoshi' => '100',
            'txid' => '',
            'updated_at' => date('Y-m-d H:i:s'),
        ], $extra);
    }

    public function testConstructorRequiresApiKey(): void
    {
        $this->expectException(Payment_Exception::class);
        new Payment_Adapter_Blockonomics([]);
    }

    public function testDeriveCallbackSecretIsDeterministic40CharHex(): void
    {
        $a = Payment_Adapter_Blockonomics::deriveCallbackSecret('salt-1');
        $b = Payment_Adapter_Blockonomics::deriveCallbackSecret('salt-1');
        $c = Payment_Adapter_Blockonomics::deriveCallbackSecret('salt-2');

        $this->assertSame($a, $b, 'same salt must derive the same secret (constant URL)');
        $this->assertNotSame($a, $c, 'different salts must derive different secrets');
        $this->assertSame(40, strlen($a));
        $this->assertTrue(ctype_xdigit($a));
        $this->assertSame(substr(hash_hmac('sha256', 'blockonomics:callback', 'salt-1'), 0, 40), $a);
    }

    public function testBuildCallbackUrlPointsAtGuestEndpointWithEncodedSecret(): void
    {
        $this->assertSame(
            'https://shop.example/api/guest/blockonomics/callback?secret=abc123',
            Payment_Adapter_Blockonomics::buildCallbackUrl('abc123')
        );
    }

    public function testUniqueTxnIdAppendsAddressAndMapsTestMarker(): void
    {
        $this->assertSame('tx1-addr1', Payment_Adapter_Blockonomics::uniqueTxnId('tx1', 'addr1'));

        // Blockonomics dashboard test payments all share one marker txid; it must map to a
        // per-address key so test callbacks for different invoices never dedup each other.
        $marker = 'WarningThisIsAGeneratedTestPaymentAndNotARealBitcoinTransaction';
        $this->assertSame('TEST-addr1', Payment_Adapter_Blockonomics::uniqueTxnId($marker, 'addr1'));
    }

    public function testIsValidUsdtTxhash(): void
    {
        $this->assertTrue(Payment_Adapter_Blockonomics::isValidUsdtTxhash('0x' . str_repeat('a', 64)));
        $this->assertFalse(Payment_Adapter_Blockonomics::isValidUsdtTxhash(str_repeat('a', 64)));
        $this->assertFalse(Payment_Adapter_Blockonomics::isValidUsdtTxhash('0x' . str_repeat('a', 63)));
        $this->assertFalse(Payment_Adapter_Blockonomics::isValidUsdtTxhash('0x' . str_repeat('g', 64)));

        // The test-mode widget's generated txhash must pass; Blockonomics' server needs it
        // on monitor_tx to simulate the confirming callbacks.
        $this->assertTrue(Payment_Adapter_Blockonomics::isValidUsdtTxhash('TestUSDTTxid_10013118_mk9xccNhi3n3QFvBhXibf4f8tYCBrDHab'));
        $this->assertFalse(Payment_Adapter_Blockonomics::isValidUsdtTxhash('TestUSDTTxid_abc'));
    }

    public function testIsStaleUnconfirmed(): void
    {
        $this->assertTrue(Payment_Adapter_Blockonomics::isStaleUnconfirmed($this->order([
            'crypto' => 'USDT',
            'status' => '0',
            'value_satoshi' => '0',
            'txid' => '0x' . str_repeat('1', 64),
            'updated_at' => date('Y-m-d H:i:s', time() - 16 * 60),
        ])));
        $this->assertTrue(Payment_Adapter_Blockonomics::isStaleUnconfirmed($this->order([
            'crypto' => 'BTC',
            'status' => '0',
            'value_satoshi' => '50',
            'txid' => 'tx1',
            'updated_at' => date('Y-m-d H:i:s', time() - 5 * 60 * 60),
        ])));
        $this->assertFalse(Payment_Adapter_Blockonomics::isStaleUnconfirmed($this->order([
            'crypto' => 'USDT',
            'status' => '0',
            'value_satoshi' => '0',
            'txid' => '0x' . str_repeat('2', 64),
            'updated_at' => date('Y-m-d H:i:s', time() - 10 * 60),
        ])));
        $this->assertFalse(Payment_Adapter_Blockonomics::isStaleUnconfirmed($this->order([
            'status' => '2',
            'updated_at' => date('Y-m-d H:i:s', time() - 5 * 60 * 60),
        ])));
        $this->assertFalse(Payment_Adapter_Blockonomics::isStaleUnconfirmed($this->order([
            'crypto' => 'USDT',
            'status' => '0',
            'value_satoshi' => '50',
            'txid' => '0x' . str_repeat('3', 64),
            'updated_at' => date('Y-m-d H:i:s', time() - 16 * 60),
        ])));
    }

    public function testAwaitingConfirmation(): void
    {
        $this->assertFalse(Payment_Adapter_Blockonomics::awaitingConfirmation($this->order(['status' => null])));
        $this->assertTrue(Payment_Adapter_Blockonomics::awaitingConfirmation($this->order(['status' => '0'])));
        $this->assertTrue(Payment_Adapter_Blockonomics::awaitingConfirmation($this->order(['status' => '1'])));
        $this->assertFalse(Payment_Adapter_Blockonomics::awaitingConfirmation($this->order(['status' => '2'])));
        $this->assertFalse(Payment_Adapter_Blockonomics::awaitingConfirmation($this->order([
            'status' => '0',
            'updated_at' => date('Y-m-d H:i:s', time() - 5 * 60 * 60),
        ])));
    }

    public function testIsUnderpaidOrder(): void
    {
        $this->assertTrue(Payment_Adapter_Blockonomics::isUnderpaidOrder($this->order([
            'status' => '2',
            'value_satoshi' => '50',
            'expected_satoshi' => '100',
        ])));
        $this->assertFalse(Payment_Adapter_Blockonomics::isUnderpaidOrder($this->order([
            'status' => '2',
            'value_satoshi' => '100',
            'expected_satoshi' => '100',
        ])));
        $this->assertFalse(Payment_Adapter_Blockonomics::isUnderpaidOrder($this->order([
            'status' => '2',
            'value_satoshi' => '125',
            'expected_satoshi' => '100',
        ])));
        $this->assertFalse(Payment_Adapter_Blockonomics::isUnderpaidOrder($this->order([
            'status' => '2',
            'value_satoshi' => '0',
            'expected_satoshi' => '100',
        ])));
        $this->assertFalse(Payment_Adapter_Blockonomics::isUnderpaidOrder($this->order([
            'status' => '1',
            'value_satoshi' => '50',
            'expected_satoshi' => '100',
        ])));
    }

    public function testScoreStorePrefersWalletsThenName(): void
    {
        $adapter = $this->adapter();
        $bare = $this->store(self::CALLBACK);
        $named = $this->store(self::CALLBACK, ['name' => 'named']);
        $walleted = $this->store(self::CALLBACK, ['name' => 'w', 'wallets' => [(object) ['id' => 1, 'crypto' => 'btc']]]);

        $this->assertSame(0, $adapter->scoreStore($bare));
        $this->assertSame(1, $adapter->scoreStore($named));
        $this->assertSame(11, $adapter->scoreStore($walleted));
    }

    public function testSelectBestStoreAndFindExactMatchingStore(): void
    {
        $adapter = $this->adapter();
        $bare = $this->store(self::CALLBACK);
        $named = $this->store(self::CALLBACK, ['name' => 'named']);
        $walleted = $this->store(self::CALLBACK, ['name' => 'w', 'wallets' => [(object) ['id' => 1, 'crypto' => 'btc']]]);

        $this->assertNull($adapter->selectBestStore([]));
        $this->assertSame($walleted, $adapter->selectBestStore([$bare, $named, $walleted]));
        $this->assertSame($named, $adapter->findExactMatchingStore([$bare, $named], self::CALLBACK));
        $this->assertNull($adapter->findExactMatchingStore([$this->store('https://other.example/x')], self::CALLBACK));
        // Near-misses are no longer auto-fixed: only a byte-exact callback URL matches.
        $this->assertNull($adapter->findExactMatchingStore(
            [$this->store('https://shop.example/api/guest/blockonomics/callback?secret=bbbb')],
            self::CALLBACK
        ));
        $this->assertNull($adapter->findExactMatchingStore(
            [$this->store('http://shop.example/api/guest/blockonomics/callback?secret=aaaa')],
            self::CALLBACK
        ));
    }
}
