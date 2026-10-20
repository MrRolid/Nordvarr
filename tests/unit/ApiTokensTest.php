<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use Nordvarr\Api\ApiTokens;
use Nordvarr\Repository\ApiTokenRepository;
use Nordvarr\Tests\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;

final class ApiTokensTest extends TestCase
{
    private PDO $pdo;
    private ApiTokens $tokens;

    protected function setUp(): void
    {
        $this->pdo = TestDb::memory();
        $this->tokens = new ApiTokens(new ApiTokenRepository($this->pdo), str_repeat('a', 64));
    }

    public function testIssuedTokenVerifiesAndIsStoredOnlyAsHash(): void
    {
        $issued = $this->tokens->issue('Ansible', 'ansible', ['inventory:write'], null);
        self::assertMatchesRegularExpression('/^nv_[0-9a-f]{12}_[0-9a-f]{48}$/', $issued['token']);

        $row = $this->tokens->verify($issued['token']);
        self::assertNotNull($row);
        self::assertSame('ansible', $row['source']);
        self::assertTrue(ApiTokens::hasScope($row, 'inventory:write'));
        self::assertFalse(ApiTokens::hasScope($row, 'events:write'));

        $dump = json_encode($this->pdo->query('SELECT * FROM api_tokens')->fetchAll());
        self::assertStringNotContainsString(substr($issued['token'], 16), (string) $dump);
        self::assertNotNull($this->pdo->query('SELECT last_used_at FROM api_tokens')->fetchColumn());
    }

    public function testWrongRevokedOrMalformedTokensAreRejected(): void
    {
        $issued = $this->tokens->issue('CI', 'ci', ['events:write'], null);
        self::assertNull($this->tokens->verify(substr($issued['token'], 0, -1) . (str_ends_with($issued['token'], '0') ? '1' : '0')));
        self::assertNull($this->tokens->verify('nesmysl'));
        $other = new ApiTokens(new ApiTokenRepository($this->pdo), str_repeat('b', 64));
        self::assertNull($other->verify($issued['token']), 'Jiné tajemství aplikace token neověří.');

        (new ApiTokenRepository($this->pdo))->revoke($issued['id']);
        self::assertNull($this->tokens->verify($issued['token']));
    }

    public function testValidation(): void
    {
        foreach ([['', 'ci', ['events:write']], ['CI', 'manual', ['events:write']], ['CI', 'Zlý Zdroj', ['events:write']], ['CI', 'ci', ['admin']]] as [$name, $source, $scopes]) {
            try {
                $this->tokens->issue($name, $source, $scopes, null);
                self::fail("Token $name/$source měl být odmítnut.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRateLimitPerMinute(): void
    {
        $issued = $this->tokens->issue('CI', 'ci', ['events:write'], null);
        $now = new DateTimeImmutable('2026-10-20T12:00:30Z');
        for ($i = 0; $i < ApiTokens::RATE_LIMIT; $i++) {
            self::assertSame(0, $this->tokens->rateLimit($issued['id'], $now));
        }
        self::assertSame(30, $this->tokens->rateLimit($issued['id'], $now));
        self::assertSame(0, $this->tokens->rateLimit($issued['id'], $now->modify('+1 minute')));
    }
}
