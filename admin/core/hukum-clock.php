<?php
declare(strict_types=1);

function hukum_now(): DateTimeImmutable
{
    $controlled = $GLOBALS['hukum_test_clock'] ?? null;
    if ($controlled instanceof DateTimeImmutable) {
        return $controlled;
    }
    return new DateTimeImmutable('now');
}

function hukum_now_timestamp(): int
{
    return hukum_now()->getTimestamp();
}

function hukum_now_string(string $format = 'Y-m-d H:i:s'): string
{
    return hukum_now()->format($format);
}

function hukum_test_clock_set(DateTimeImmutable $now): void
{
    if (strtolower((string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? ''))) !== 'test') {
        throw new RuntimeException('Test clock hanya dapat digunakan pada APP_ENV=test.');
    }
    $GLOBALS['hukum_test_clock'] = $now;
}

function hukum_test_clock_advance(int $seconds): void
{
    if ($seconds < 0) {
        throw new InvalidArgumentException('Test clock tidak dapat dimundurkan.');
    }
    hukum_test_clock_set(hukum_now()->modify("+{$seconds} seconds"));
}

function hukum_test_clock_reset(): void
{
    unset($GLOBALS['hukum_test_clock']);
}
