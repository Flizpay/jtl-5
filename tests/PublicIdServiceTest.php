<?php

declare(strict_types=1);

use Plugin\flizpay\src\Service\ConfigService;
use Plugin\flizpay\src\Service\PublicIdService;

class PublicIdServiceTest extends TestCase
{
    private int $calls = 0;

    private int $now = 1_800_000_000;

    /**
     * @param string|null|\Throwable $response what the fake FLIZpay API returns
     */
    private function make(
        InMemoryConfigService $config,
        string|null|\Throwable $response = 'pub_123',
    ): PublicIdService {
        $this->calls = 0;

        return new PublicIdService(
            $config,
            function (string $apiKey) use ($response): ?string {
                $this->calls++;
                if ($response instanceof \Throwable) {
                    throw $response;
                }

                return $response;
            },
            fn(): int => $this->now,
        );
    }

    private function connected(): InMemoryConfigService
    {
        return new InMemoryConfigService(['flizpay_apiKey' => 'key']);
    }

    public function testResolveNeverContactsFlizpay(): void
    {
        $config  = $this->connected();
        $service = $this->make($config);

        $this->assertSame('', $service->resolve());
        $config->set(ConfigService::KEY_PUBLIC_ID, 'pub_stored');
        $this->assertSame('pub_stored', $service->resolve());
        $this->assertSame(0, $this->calls);
    }

    public function testResolveIsEmptyWithoutApiKey(): void
    {
        $config = new InMemoryConfigService();
        $config->set(ConfigService::KEY_PUBLIC_ID, 'pub_stale');

        $this->assertSame('', $this->make($config)->resolve());
    }

    public function testEnsureStoresIdAndClearsThrottle(): void
    {
        $config = $this->connected();
        $config->set(ConfigService::KEY_PUBLIC_ID_RETRY_AT, (string)($this->now - 10));
        $service = $this->make($config, ' pub_123 ');

        $this->assertSame('pub_123', $service->ensure());
        $this->assertSame('pub_123', $config->get(ConfigService::KEY_PUBLIC_ID));
        $this->assertSame(null, $config->get(ConfigService::KEY_PUBLIC_ID_RETRY_AT));
        $this->assertSame(1, $this->calls);
    }

    public function testEnsureReturnsStoredIdWithoutFetching(): void
    {
        $config = $this->connected();
        $config->set(ConfigService::KEY_PUBLIC_ID, 'pub_stored');
        $service = $this->make($config);

        $this->assertSame('pub_stored', $service->ensure());
        $this->assertSame(0, $this->calls);
    }

    public function testEnsureSetsThrottleOnMissingId(): void
    {
        $config  = $this->connected();
        $service = $this->make($config, null);

        $this->assertSame('', $service->ensure());
        $this->assertSame((string)$this->now, $config->get(ConfigService::KEY_PUBLIC_ID_RETRY_AT));
        $this->assertSame(null, $config->get(ConfigService::KEY_PUBLIC_ID));
    }

    public function testEnsureSetsThrottleOnFailure(): void
    {
        $config  = $this->connected();
        $service = $this->make($config, new \RuntimeException('down'));

        $this->assertSame('', $service->ensure());
        $this->assertSame((string)$this->now, $config->get(ConfigService::KEY_PUBLIC_ID_RETRY_AT));
    }

    public function testThrottledEnsureSkipsFetchWithinBackoff(): void
    {
        $config = $this->connected();
        $config->set(ConfigService::KEY_PUBLIC_ID_RETRY_AT, (string)($this->now - 60));
        $service = $this->make($config);

        $this->assertSame('', $service->ensure(true));
        $this->assertSame(0, $this->calls);
    }

    public function testThrottledEnsureRetriesAfterBackoff(): void
    {
        $config = $this->connected();
        $config->set(ConfigService::KEY_PUBLIC_ID_RETRY_AT, (string)($this->now - 3600));
        $service = $this->make($config);

        $this->assertSame('pub_123', $service->ensure(true));
        $this->assertSame(1, $this->calls);
    }

    public function testUnthrottledEnsureIgnoresBackoff(): void
    {
        $config = $this->connected();
        $config->set(ConfigService::KEY_PUBLIC_ID_RETRY_AT, (string)($this->now - 60));
        $service = $this->make($config);

        $this->assertSame('pub_123', $service->ensure());
        $this->assertSame(1, $this->calls);
    }

    public function testEnsureIsEmptyWithoutApiKey(): void
    {
        $service = $this->make(new InMemoryConfigService());

        $this->assertSame('', $service->ensure());
        $this->assertSame(0, $this->calls);
    }

    public function testClearPublicIdDropsIdAndThrottle(): void
    {
        $config = $this->connected();
        $config->set(ConfigService::KEY_PUBLIC_ID, 'pub_old');
        $config->set(ConfigService::KEY_PUBLIC_ID_RETRY_AT, '1');
        $config->clearPublicId();

        $this->assertSame('', $config->getPublicId());
        $this->assertSame(null, $config->get(ConfigService::KEY_PUBLIC_ID_RETRY_AT));
    }
}
