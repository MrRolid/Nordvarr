<?php

declare(strict_types=1);

namespace Nordvarr\Alerts;

use DateTimeImmutable;
use DateTimeZone;
use Nordvarr\I18n;
use Nordvarr\Notify\Notification;
use Nordvarr\Time;

/**
 * Text notifikací k alertům (jazyk a časová zóna podle nastavení).
 */
final class NotificationBuilder
{
    public function __construct(
        private readonly I18n $i18n,
        private readonly string $timezone,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $alert řádek z AlertRepository (s object_name a cause_name)
     * @param array<string, mixed>|null $check kontrola (pro cíl), může být smazaná
     */
    public function forAlert(string $kind, array $alert, ?array $check): Notification
    {
        $t = fn (string $key, array $p = []): string => $this->i18n->t($key, $p);
        $checkLabel = $t('checktype.' . $alert['check_type']) . ((string) $alert['check_name'] !== '' ? ' ' . $alert['check_name'] : '');
        $reason = (string) ($alert['reason_code'] ?? '');
        $headline = match ($kind) {
            'resolved' => $t('notify.subject.resolved'),
            'reminder' => $t('notify.subject.reminder'),
            'after_maintenance' => $t('notify.subject.after_maintenance'),
            'unsuppressed' => $t('notify.subject.unsuppressed'),
            default => $t('notify.subject.' . ($alert['level'] === 'degraded' ? 'degraded' : 'opened')),
        };
        $subject = '[Nordvarr] ' . $headline . ': ' . $alert['object_name'] . ' (' . $checkLabel
            . ($kind !== 'resolved' && $reason !== '' ? ': ' . $reason : '') . ')';

        $target = $check !== null ? \Nordvarr\Checks\CheckTypes::target((string) $check['type'], $check['config']) : '';
        $url = $this->baseUrl !== '' ? rtrim($this->baseUrl, '/') . '/objects/' . $alert['object_id'] : '';
        $lines = [
            $t('notify.object') . ': ' . $alert['object_name'],
            $t('notify.check') . ': ' . $checkLabel . ($target !== '' ? ' (' . $target . ')' : ''),
            $t('notify.state') . ': ' . ($kind === 'resolved' ? $t('status.healthy') : $t('status.' . $alert['level'])),
        ];
        if ($kind !== 'resolved' && $reason !== '') {
            $lines[] = $t('notify.reason') . ': ' . $reason . ((string) $alert['reason_detail'] !== '' ? ' - ' . $alert['reason_detail'] : '');
        }
        $lines[] = $t('notify.since') . ': ' . $this->time((string) $alert['opened_at']);
        if ($kind === 'resolved' && $alert['resolved_at'] !== null) {
            $lines[] = $t('notify.resolved_at') . ': ' . $this->time((string) $alert['resolved_at']);
        }
        if ($alert['probable_cause_object_id'] !== null && $alert['cause_name'] !== null) {
            $lines[] = $t('notify.cause') . ': ' . $alert['cause_name'];
        }
        if ($url !== '') {
            $lines[] = $url;
        }

        return new Notification($kind, $subject, implode("\n", $lines), [
            'event' => 'alert.' . $kind,
            'alert' => [
                'id' => $alert['id'],
                'status' => $alert['status'],
                'level' => $alert['level'],
                'opened_at' => $alert['opened_at'],
                'resolved_at' => $alert['resolved_at'],
            ],
            'object' => ['id' => $alert['object_id'], 'name' => $alert['object_name'], 'url' => $url],
            'check' => [
                'id' => $alert['check_id'],
                'type' => $alert['check_type'],
                'name' => $alert['check_name'],
                'target' => $target,
                'reason_code' => $alert['reason_code'],
                'reason_detail' => $alert['reason_detail'],
            ],
            'probable_cause' => $alert['probable_cause_object_id'] === null ? null
                : ['object_id' => $alert['probable_cause_object_id'], 'name' => $alert['cause_name']],
            'sent_at' => Time::toDb(Time::now()),
        ]);
    }

    public function test(string $channelName): Notification
    {
        return new Notification('test', '[Nordvarr] ' . $this->i18n->t('notify.subject.test'), $this->i18n->t('notify.test_text', ['channel' => $channelName]), [
            'event' => 'test',
            'channel' => $channelName,
            'sent_at' => Time::toDb(Time::now()),
        ]);
    }

    private function time(string $utc): string
    {
        $zone = in_array($this->timezone, timezone_identifiers_list(), true) ? $this->timezone : 'UTC';

        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($zone))->format('j. n. Y H:i') . ' (' . $zone . ')';
    }
}
