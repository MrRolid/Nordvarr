<?php

declare(strict_types=1);

namespace Nordvarr\Api;

use DateTimeImmutable;
use InvalidArgumentException;
use Nordvarr\Inventory\Vocabulary;
use Nordvarr\Repository\ApiTokenRepository;

/**
 * Vydávání a ověřování API tokenů. Token má tvar nv_<prefix>_<tajemství>,
 * v databázi je jen prefix a HMAC-SHA256 celého tokenu s tajemstvím aplikace.
 */
final class ApiTokens
{
    public const SCOPES = ['inventory:write', 'events:write', 'context:read', 'agent:write'];
    public const RATE_LIMIT = 120;
    /** Zdroje, které API token nesmí používat (vyhrazené pro UI a import). */
    public const RESERVED_SOURCES = [Vocabulary::MANUAL_SOURCE, 'import'];

    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly string $appSecret,
    ) {
    }

    /**
     * Vydá nový token. Čitelná podoba se vrací jen jednou.
     *
     * @param list<string> $scopes
     * @return array{id: string, token: string}
     */
    public function issue(string $name, string $source, array $scopes, ?string $createdBy): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('settings.token_err_name');
        }
        if (!Vocabulary::isValidSource($source) || in_array($source, self::RESERVED_SOURCES, true)) {
            throw new InvalidArgumentException('settings.token_err_source');
        }
        $scopes = array_values(array_intersect(self::SCOPES, $scopes));
        if ($scopes === []) {
            throw new InvalidArgumentException('settings.token_err_scope');
        }
        $prefix = bin2hex(random_bytes(6));
        $token = 'nv_' . $prefix . '_' . bin2hex(random_bytes(24));
        $id = $this->tokens->create($name, $prefix, $this->hash($token), $source, $scopes, $createdBy);

        return ['id' => $id, 'token' => $token];
    }

    /**
     * Ověří token z hlavičky. Vrací záznam tokenu, nebo null.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $token): ?array
    {
        if (preg_match('/^nv_([0-9a-f]{12})_[0-9a-f]{48}$/', $token, $m) !== 1) {
            return null;
        }
        $row = $this->tokens->findByPrefix($m[1]);
        if ($row === null || $row['revoked_at'] !== null || !hash_equals((string) $row['hash'], $this->hash($token))) {
            return null;
        }
        $this->tokens->touch((string) $row['id']);

        return $row;
    }

    /**
     * Započítá požadavek. Vrací počet sekund do konce okna, když je limit překročen, jinak 0.
     */
    public function rateLimit(string $tokenId, DateTimeImmutable $now): int
    {
        $window = $now->format('Y-m-d\TH:i');
        if ($this->tokens->hit($tokenId, $window) > self::RATE_LIMIT) {
            return 60 - (int) $now->format('s');
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $token
     */
    public static function hasScope(array $token, string $scope): bool
    {
        return in_array($scope, explode(' ', (string) $token['scopes']), true);
    }

    private function hash(string $token): string
    {
        return hash_hmac('sha256', $token, $this->appSecret);
    }
}
