<?php

declare(strict_types=1);

namespace Nordvarr\Notify;

/**
 * Společné rozhraní notifikačních kanálů. Nový typ (např. hlasový hovor)
 * stačí implementovat a zaregistrovat v ChannelFactory, logika alertů se nemění.
 */
interface Channel
{
    /**
     * Odešle zprávu. Při neúspěchu vyhodí ChannelException.
     *
     * @return string krátká poznámka k úspěšnému odeslání (např. použitý server)
     */
    public function send(Notification $notification): string;
}
