<?php

declare(strict_types=1);

namespace Nordvarr\Http\Client;

/**
 * Omezení curl jen na http a https (i pro přesměrování).
 *
 * CURLOPT_PROTOCOLS_STR existuje až s libcurl 7.85; na starších systémech (např. AlmaLinux 9
 * s libcurl 7.76) PHP konstantu nedefinuje a jejím použitím by skript spadl. Tam se použije
 * starší číselná varianta CURLOPT_PROTOCOLS.
 */
final class CurlProtocols
{
    /**
     * @return array<int, int|string>
     */
    public static function options(?bool $stringOptions = null): array
    {
        $stringOptions ??= defined('CURLOPT_PROTOCOLS_STR') && defined('CURLOPT_REDIR_PROTOCOLS_STR');
        if ($stringOptions) {
            return [(int) constant('CURLOPT_PROTOCOLS_STR') => 'http,https', (int) constant('CURLOPT_REDIR_PROTOCOLS_STR') => 'http,https'];
        }

        return [CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS];
    }
}
