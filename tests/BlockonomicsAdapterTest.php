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

    public function testClassifyStoresBucketsExactAndSafePartialOnly(): void
    {
        $exact = $this->store(self::CALLBACK, ['name' => 'exact']);
        $partialSecret = $this->store('https://shop.example/api/guest/blockonomics/callback?secret=bbbb', ['name' => 'partial-secret']);
        $partialProtocol = $this->store('http://shop.example/api/guest/blockonomics/callback?secret=cccc', ['name' => 'partial-protocol']);
        $otherHost = $this->store('https://other.example/api/guest/blockonomics/callback?secret=aaaa');
        $otherPath = $this->store('https://shop.example/callback?secret=aaaa');
        $extraQueryKey = $this->store('https://shop.example/api/guest/blockonomics/callback?secret=aaaa&x=1');
        $emptyCallback = $this->store('');

        $matches = $this->adapter()->classifyStores(
            [$exact, $partialSecret, $partialProtocol, $otherHost, $otherPath, $extraQueryKey, $emptyCallback],
            self::CALLBACK
        );

        $this->assertSame([$exact], $matches['exact']);
        // Safe partial = our exact path + host, differing only in secret (or protocol) — never
        // another site's store, never a URL with extra query params.
        $this->assertSame([$partialSecret, $partialProtocol], $matches['partial']);
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
    }
}
