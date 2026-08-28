<?php

declare(strict_types=1);

/**
 * Minimal assertion harness so the plugin's logic tests run with a plain
 * `php tests/run.php`, without composer or a shop installation.
 */
abstract class TestCase
{
    public static int $passed = 0;

    /** @var string[] */
    public static array $failures = [];

    private string $currentTest = '';

    public function run(): void
    {
        foreach (\get_class_methods($this) as $method) {
            if (!\str_starts_with($method, 'test')) {
                continue;
            }
            $this->currentTest = static::class . '::' . $method;
            try {
                $this->$method();
            } catch (Throwable $e) {
                self::$failures[] = $this->currentTest . ' threw ' . $e::class . ': ' . $e->getMessage();
            }
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected === $actual) {
            self::$passed++;

            return;
        }
        self::$failures[] = \sprintf(
            "%s\n    %s\n    expected: %s\n    actual:   %s",
            $this->currentTest,
            $message,
            \var_export($expected, true),
            \var_export($actual, true)
        );
    }

    protected function assertTrue(bool $actual, string $message = ''): void
    {
        $this->assertSame(true, $actual, $message);
    }

    protected function assertFalse(bool $actual, string $message = ''): void
    {
        $this->assertSame(false, $actual, $message);
    }
}
