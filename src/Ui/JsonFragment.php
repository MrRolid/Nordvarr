<?php

declare(strict_types=1);

namespace Nordvarr\Ui;

use Nordvarr\Http\Response;

/**
 * JSON odpovědi pro obnovování části stránky (hotový HTML fragment nebo data).
 * ETag je SHA-1 obsahu; při shodě s If-None-Match vrací 304 bez těla.
 */
final class JsonFragment
{
    /**
     * @param array<string, mixed> $extra další položky JSON
     */
    public static function response(Ctx $ctx, string $html, array $extra = []): Response
    {
        return self::json($ctx, ['html' => $html] + $extra);
    }

    /**
     * Libovolná JSON data s ETag a podporou If-None-Match.
     *
     * @param array<string, mixed> $data
     */
    public static function json(Ctx $ctx, array $data): Response
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $etag = '"' . sha1($body) . '"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'no-cache, private'];
        $sent = (string) $ctx->request->header('If-None-Match');
        if ($sent !== '' && in_array($etag, array_map('trim', explode(',', $sent)), true)) {
            return new Response('', 304, $headers);
        }

        return new Response($body, 200, $headers + ['Content-Type' => 'application/json; charset=utf-8']);
    }
}
