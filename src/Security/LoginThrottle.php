<?php

declare(strict_types=1);

namespace Nordvarr\Security;

use DateTimeImmutable;
use Nordvarr\Repository\LoginAttemptRepository;
use Nordvarr\Time;

/**
 * Omezení pokusů o přihlášení podle účtu i IP adresy.
 *
 * Počítají se neúspěšné pokusy od posledního úspěchu za poslední hodinu.
 * Od 5 selhání musí mezi pokusy uplynout postupně delší doba (1, 2, 4, 8, 16 s),
 * od 10 selhání je přihlášení zamčené na 15 minut od posledního selhání.
 */
final class LoginThrottle
{
    public const DELAY_FROM = 5;
    public const LOCK_FROM = 10;
    public const LOCK_SECONDS = 900;
    private const LOOKBACK_SECONDS = 3600;

    public function __construct(private readonly LoginAttemptRepository $attempts)
    {
    }

    /**
     * Vrací počet sekund, po které je další pokus odmítnut (0 = pokus je povolen).
     */
    public function retryAfter(string $email, string $ip, DateTimeImmutable $now): int
    {
        $since = $now->modify('-' . self::LOOKBACK_SECONDS . ' seconds');

        return max(
            $this->retryAfterFor($this->attempts->failuresSinceLastSuccess('email', $email, $since), $now),
            $this->retryAfterFor($this->attempts->failuresSinceLastSuccess('ip', $ip, $since), $now),
        );
    }

    public function record(string $email, string $ip, bool $success, DateTimeImmutable $now): void
    {
        $this->attempts->record($email, $ip, $success, $now);
        if ($success) {
            $this->attempts->purgeOlderThan($now->modify('-30 days'));
        }
    }

    /**
     * @param list<string> $failures nejnovější první
     */
    private function retryAfterFor(array $failures, DateTimeImmutable $now): int
    {
        $count = count($failures);
        if ($count < self::DELAY_FROM) {
            return 0;
        }
        $wait = $count >= self::LOCK_FROM ? self::LOCK_SECONDS : 2 ** ($count - self::DELAY_FROM);
        $elapsed = $now->getTimestamp() - Time::fromDb($failures[0])->getTimestamp();

        return max(0, $wait - $elapsed);
    }
}
