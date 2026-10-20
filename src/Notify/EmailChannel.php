<?php

declare(strict_types=1);

namespace Nordvarr\Notify;

/**
 * E-mail přes SMTP s primárním a záložním serverem: když primární selže,
 * zkusí se záložní.
 */
final class EmailChannel implements Channel
{
    /**
     * @param list<string> $to
     */
    public function __construct(
        private readonly SmtpClient $client,
        private readonly string $from,
        private readonly array $to,
        private readonly SmtpServer $primary,
        private readonly ?SmtpServer $fallback = null,
    ) {
    }

    public function send(Notification $notification): string
    {
        $message = MailMessage::build($this->from, $this->to, $notification->subject, $notification->text);
        try {
            $this->client->send($this->primary, $this->from, $this->to, $message);

            return 'primární server ' . $this->primary->label();
        } catch (SmtpException $primaryError) {
            if ($this->fallback === null) {
                throw new ChannelException('SMTP ' . $this->primary->label() . ': ' . $primaryError->getMessage(), 0, $primaryError);
            }
            try {
                $this->client->send($this->fallback, $this->from, $this->to, $message);
            } catch (SmtpException $fallbackError) {
                throw new ChannelException(
                    'SMTP ' . $this->primary->label() . ': ' . $primaryError->getMessage()
                    . '; záložní ' . $this->fallback->label() . ': ' . $fallbackError->getMessage(),
                    0,
                    $fallbackError
                );
            }

            return 'záložní server ' . $this->fallback->label() . ' (primární: ' . $primaryError->getMessage() . ')';
        }
    }
}
