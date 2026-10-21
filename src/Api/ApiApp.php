<?php

declare(strict_types=1);

namespace Nordvarr\Api;

use JsonException;
use Nordvarr\Http\Request;
use Nordvarr\Http\Response;
use Nordvarr\Http\Router;
use Nordvarr\Agent\AgentService;
use Nordvarr\Export\LlmContext;
use Nordvarr\I18n;
use Nordvarr\Inventory\EventIngest;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Inventory\Upsert;
use Nordvarr\Inventory\ValidationException;
use Nordvarr\Repository\ApiTokenRepository;
use Nordvarr\Repository\SettingsRepository;
use Nordvarr\Time;
use PDO;

/**
 * Strojové API /api/v1/. Autentizace jen tokenem v hlavičce Authorization,
 * cookies ani session se nepoužívají.
 */
final class ApiApp
{
    public const MAX_BODY = 1048576;

    private readonly string $appRoot;

    public function __construct(private readonly PDO $pdo, private readonly string $appSecret, ?string $appRoot = null)
    {
        $this->appRoot = $appRoot ?? dirname(__DIR__, 2);
    }

    public function handle(Request $request): Response
    {
        $router = new Router();
        $router->post('/api/v1/objects/upsert', fn (): Response => $this->write($request, 'inventory:write',
            fn (array $input, string $source): Response => $this->upsert($input, $source)));
        $router->post('/api/v1/events', fn (): Response => $this->write($request, 'events:write',
            fn (array $input, string $source): Response => $this->event($input, $source)));
        $router->post('/api/v1/agent/report', fn (): Response => $this->write($request, 'agent:write',
            fn (array $input, string $source): Response => self::json((new AgentService($this->pdo))->ingest($input, $source))));
        $router->get('/api/v1/objects/{id}/context', fn (array $p): Response => $this->context($request, $p['id']));

        $match = $router->match($request->method, $request->path);
        if ($match['status'] === 404) {
            return self::json(['error' => 'not_found'], 404);
        }
        if ($match['status'] === 405) {
            return self::json(['error' => 'method_not_allowed'], 405, ['Allow' => implode(', ', $match['allowed'])]);
        }

        return ($match['handler'])($match['params']);
    }

    /**
     * Společný průchod zapisujících požadavků: token, scope, limit, JSON.
     *
     * @param callable(array<string, mixed>, string): Response $action
     */
    private function write(Request $request, string $scope, callable $action): Response
    {
        $token = $this->authenticate($request, $scope);
        if ($token instanceof Response) {
            return $token;
        }
        if (strlen($request->body) > self::MAX_BODY) {
            return self::json(['error' => 'payload_too_large'], 413);
        }
        if (!str_starts_with(strtolower((string) $request->header('Content-Type')), 'application/json')) {
            return self::json(['error' => 'unsupported_media_type'], 415);
        }
        try {
            $input = json_decode($request->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::json(['error' => 'invalid_json'], 400);
        }
        if (!is_array($input) || array_is_list($input)) {
            return self::json(['error' => 'invalid_json'], 400);
        }

        try {
            return $action($input, (string) $token['source']);
        } catch (ValidationException $e) {
            $body = ['error' => $e->missing !== [] ? 'missing_objects' : 'validation_failed', 'fields' => $e->errors];
            if ($e->missing !== []) {
                $body['missing_external_ids'] = $e->missing;
            }

            return self::json($body, 422);
        }
    }

    /**
     * Token z hlavičky, limit požadavků a scope. Vrací řádek tokenu, nebo chybovou odpověď.
     *
     * @return array<string, mixed>|Response
     */
    private function authenticate(Request $request, string $scope): array|Response
    {
        $tokens = new ApiTokens(new ApiTokenRepository($this->pdo), $this->appSecret);
        $header = (string) $request->header('Authorization');
        $token = preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1 ? $tokens->verify($m[1]) : null;
        if ($token === null) {
            return self::json(['error' => 'unauthorized'], 401, ['WWW-Authenticate' => 'Bearer']);
        }
        $retryAfter = $tokens->rateLimit((string) $token['id'], Time::now());
        if ($retryAfter > 0) {
            return self::json(['error' => 'rate_limited'], 429, ['Retry-After' => (string) $retryAfter]);
        }
        if (!ApiTokens::hasScope($token, $scope)) {
            return self::json(['error' => 'insufficient_scope', 'required' => $scope], 403);
        }

        return $token;
    }

    /**
     * Export pro LLM jako JSON (nebo Markdown s ?format=markdown).
     */
    private function context(Request $request, string $objectId): Response
    {
        $token = $this->authenticate($request, 'context:read');
        if ($token instanceof Response) {
            return $token;
        }
        $settings = new SettingsRepository($this->pdo);
        $tz = (string) ($settings->get('timezone', 'Europe/Prague') ?? 'Europe/Prague');
        $context = new LlmContext(
            $this->pdo,
            new I18n($this->appRoot . '/lang', I18n::resolve($request->queryString('lang') !== '' ? $request->queryString('lang') : $settings->get('notification_lang'))),
            in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC',
        );
        $data = $context->build($objectId);
        if ($data === null) {
            return self::json(['error' => 'not_found'], 404);
        }
        if ($request->queryString('format') === 'markdown') {
            return new Response($context->markdown($data), 200, [
                'Content-Type' => 'text/markdown; charset=utf-8',
                'Cache-Control' => 'no-store',
            ]);
        }

        return self::json($data);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function upsert(array $input, string $source): Response
    {
        $result = (new Upsert(new Inventory($this->pdo)))->run($input, $source);

        return self::json($result, $result['created'] ? 201 : 200);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function event(array $input, string $source): Response
    {
        $result = (new EventIngest(new Inventory($this->pdo)))->run($input, $source);

        return self::json($result, $result['created'] ? 201 : 200);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): Response
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new Response($body, $status, $headers + [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
