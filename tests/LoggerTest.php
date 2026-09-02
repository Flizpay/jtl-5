<?php

declare(strict_types=1);

use Plugin\flizpay\src\FlizPlugin;
use Plugin\flizpay\src\Service\Logger;

/**
 * Covers the debug gate, the always-on notice/error path and the scalar-only
 * context contract.
 */
class LoggerTest extends TestCase
{
    /** @var array<int, array{level:int, message:string, context:array, data:string}> */
    private array $entries = [];

    private function capture(bool $debugEnabled, callable $fn): void
    {
        $this->entries = [];
        Logger::setSink(function (int $level, string $message, array $context, string $data): void {
            $this->entries[] = ['level' => $level, 'message' => $message, 'context' => $context, 'data' => $data];
        });
        Logger::setDebugEnabled($debugEnabled);
        try {
            $fn();
        } finally {
            Logger::setSink(static function (): void {});
            Logger::setDebugEnabled(false);
        }
    }

    public function testDebugIsDroppedWhileDisabled(): void
    {
        $this->capture(false, static function (): void {
            Logger::debug('hidden');
            FlizPlugin::debug('hidden too');
        });

        $this->assertSame(0, \count($this->entries), 'debug entries are not written while the setting is off');
    }

    public function testDebugIsWrittenWhileEnabled(): void
    {
        $this->capture(true, static function (): void {
            Logger::debug('visible', ['http' => 200]);
        });

        $this->assertSame(1, \count($this->entries));
        $this->assertSame(\LOGLEVEL_DEBUG, $this->entries[0]['level']);
        $this->assertSame('visible', $this->entries[0]['message']);
        $this->assertSame(['http' => 200], $this->entries[0]['context']);
    }

    public function testNoticeAndErrorAlwaysPass(): void
    {
        $this->capture(false, static function (): void {
            Logger::notice('n');
            Logger::error('e');
            FlizPlugin::log('legacy', \LOGLEVEL_ERROR, ['order' => 5]);
        });

        $this->assertSame(3, \count($this->entries), 'notice/error written regardless of the debug setting');
        $this->assertSame(\LOGLEVEL_NOTICE, $this->entries[0]['level']);
        $this->assertSame(\LOGLEVEL_ERROR, $this->entries[1]['level']);
        $this->assertSame(['order' => 5], $this->entries[2]['context'], 'FlizPlugin::log() forwards its context');
    }

    public function testNonScalarContextIsDropped(): void
    {
        $this->capture(true, static function (): void {
            Logger::debug('payload', [
                'order'    => 12,
                'request'  => ['apiKey' => 'secret', 'customer' => ['email' => 'a@b.c']],
                'response' => new \stdClass(),
                'ok'       => true,
                'none'     => null,
            ]);
        });

        $this->assertSame(
            ['order' => 12, 'ok' => true, 'none' => null],
            $this->entries[0]['context'],
            'arrays and objects never reach the log'
        );
    }

    public function testLongStringsAreTruncated(): void
    {
        $this->capture(true, static function (): void {
            Logger::debug('long', ['error' => \str_repeat('x', 1000)]);
        });

        $this->assertSame(256 + \strlen('…'), \strlen($this->entries[0]['context']['error']));
    }

    public function testCorrelationTokenForPaymentLog(): void
    {
        $this->capture(false, static function (): void {
            Logger::notice('booked', ['order' => 42, 'tx' => 'tx_1', 'attempt' => 2, 'amount' => '1.00']);
            Logger::error('plain');
        });

        $this->assertSame('order:42 tx:tx_1 attempt:2', $this->entries[0]['data']);
        $this->assertSame('', $this->entries[1]['data']);
    }

    public function testFilePathLivesInJtlLogDirectory(): void
    {
        $this->assertSame(\rtrim(\PFAD_LOGFILES, '/') . '/flizpay.log', Logger::getFilePath());
    }
}
