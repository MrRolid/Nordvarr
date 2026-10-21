<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Nordvarr\Repository\LoginAttemptRepository;
use Nordvarr\Security\LoginThrottle;
use Nordvarr\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class LoginThrottleTest extends TestCase
{
    private LoginThrottle $throttle;
    private DateTimeImmutable $t0;

    protected function setUp(): void
    {
        $this->throttle = new LoginThrottle(new LoginAttemptRepository(TestDb::memory()));
        $this->t0 = new DateTimeImmutable('2026-10-20T12:00:00Z', new DateTimeZone('UTC'));
    }

    private function failAttempts(int $count, string $email = 'a@example.com', string $ip = '192.0.2.1', int $offset = 0): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->throttle->record($email, $ip, false, $this->t0->modify('+' . ($offset + $i) . ' seconds'));
        }
    }

    public function testFourFailuresAreNotThrottled(): void
    {
        $this->failAttempts(4);
        self::assertSame(0, $this->throttle->retryAfter('a@example.com', '192.0.2.1', $this->t0->modify('+4 seconds')));
    }

    public function testProgressiveDelayFromFiveFailures(): void
    {
        $this->failAttempts(5); // poslední selhání v t0+4
        self::assertSame(1, $this->throttle->retryAfter('a@example.com', '192.0.2.9', $this->t0->modify('+4 seconds')));
        self::assertSame(0, $this->throttle->retryAfter('a@example.com', '192.0.2.9', $this->t0->modify('+5 seconds')));

        $this->failAttempts(2, offset: 10); // 7 selhání, poslední v t0+11 -> čekat 4 s
        self::assertSame(4, $this->throttle->retryAfter('a@example.com', '192.0.2.9', $this->t0->modify('+11 seconds')));
    }

    public function testLockAfterTenFailuresForFifteenMinutes(): void
    {
        $this->failAttempts(10, offset: 0); // poslední v t0+9
        $now = $this->t0->modify('+9 seconds');
        self::assertSame(900, $this->throttle->retryAfter('a@example.com', '198.51.100.7', $now));
        self::assertSame(1, $this->throttle->retryAfter('a@example.com', '198.51.100.7', $now->modify('+899 seconds')));
        self::assertSame(0, $this->throttle->retryAfter('a@example.com', '198.51.100.7', $now->modify('+900 seconds')));
    }

    public function testLockAppliesPerIpAcrossAccounts(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->throttle->record("user$i@example.com", '203.0.113.5', false, $this->t0->modify("+$i seconds"));
        }
        $now = $this->t0->modify('+10 seconds');
        self::assertGreaterThan(0, $this->throttle->retryAfter('jiny@example.com', '203.0.113.5', $now));
        self::assertSame(0, $this->throttle->retryAfter('jiny@example.com', '203.0.113.6', $now));
    }

    public function testSuccessResetsCounter(): void
    {
        $this->failAttempts(9);
        $this->throttle->record('a@example.com', '192.0.2.1', true, $this->t0->modify('+20 seconds'));
        $this->failAttempts(1, offset: 21);
        self::assertSame(0, $this->throttle->retryAfter('a@example.com', '192.0.2.1', $this->t0->modify('+22 seconds')));
    }

    public function testOldFailuresExpire(): void
    {
        $this->failAttempts(10);
        self::assertSame(0, $this->throttle->retryAfter('a@example.com', '192.0.2.1', $this->t0->modify('+2 hours')));
    }
}
