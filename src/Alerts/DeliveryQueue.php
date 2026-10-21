<?php

declare(strict_types=1);

namespace Nordvarr\Alerts;

use DateTimeImmutable;
use Nordvarr\Notify\Channel;
use Nordvarr\Notify\Notification;
use Nordvarr\Repository\ChannelRepository;
use Nordvarr\Repository\DeliveryRepository;
use Nordvarr\Time;
use PDO;
use Throwable;

/**
 * Odesílání z fronty deliveries. Neúspěšný pokus se opakuje po 1, 5, 15 a 60 minutách,
 * potom je doručení ve stavu failed.
 */
final class DeliveryQueue
{
    /** Prodlevy před opakováním (s) po 1., 2., 3. a 4. neúspěšném pokusu. */
    public const RETRY_DELAYS = [60, 300, 900, 3600];

    private DeliveryRepository $deliveries;
    private ChannelRepository $channels;

    /**
     * @param callable(array<string, mixed>): Channel $channelFactory vytvoří kanál z řádku channels
     */
    public function __construct(PDO $pdo, private $channelFactory)
    {
        $this->deliveries = new DeliveryRepository($pdo);
        $this->channels = new ChannelRepository($pdo);
    }

    /**
     * @return array{sent: int, failed: int}
     */
    public function process(float $deadline = INF, ?DateTimeImmutable $now = null): array
    {
        $now ??= Time::now();
        $stats = ['sent' => 0, 'failed' => 0];
        foreach ($this->deliveries->due(Time::toDb($now)) as $delivery) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $ok = $this->attempt($delivery);
            $stats[$ok ? 'sent' : 'failed']++;
        }

        return $stats;
    }

    /**
     * Jeden pokus o doručení (i mimo plán, např. testovací odeslání z UI).
     *
     * @param array<string, mixed> $delivery
     */
    public function attempt(array $delivery): bool
    {
        $now = Time::now();
        $at = Time::toDb($now);
        $channel = $this->channels->find((string) $delivery['channel_id']);
        $error = '';
        $ok = false;
        if ($channel === null || (!$channel['enabled'] && $delivery['kind'] !== 'test')) {
            $error = 'Kanál je vypnutý nebo smazaný.';
        } else {
            try {
                $payload = json_decode((string) $delivery['payload'], true);
                $note = ($this->channelFactory)($channel)->send(new Notification(
                    (string) $delivery['kind'],
                    (string) $delivery['subject'],
                    (string) $delivery['body'],
                    is_array($payload) ? $payload : [],
                ));
                $ok = true;
                $error = $note;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
        $attempts = (int) $delivery['attempts'] + 1;
        $next = null;
        // Testovací odeslání se neopakuje, výsledek se ukáže hned v UI.
        if (!$ok && $delivery['kind'] !== 'test' && $error !== 'Kanál je vypnutý nebo smazaný.' && isset(self::RETRY_DELAYS[$attempts - 1])) {
            $next = Time::toDb($now->modify('+' . self::RETRY_DELAYS[$attempts - 1] . ' seconds'));
        }
        // U úspěchu se do historie pokusů ukládá poznámka kanálu (např. použitý server).
        $this->deliveries->recordAttempt((string) $delivery['id'], $ok, $error, $at, $next);

        return $ok;
    }
}
