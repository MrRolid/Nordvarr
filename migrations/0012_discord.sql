-- F22: kanál typu "discord" (webhook Discordu se zprávou ve formátu Discordu).
-- Typ je v CHECK omezení, tabulka se přestaví (Migrator běží s vypnutými cizími klíči a poté je ověří).
CREATE TABLE channels_new (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('email', 'telegram', 'webhook', 'discord')),
    config TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
INSERT INTO channels_new SELECT id, name, type, config, enabled, created_at, updated_at FROM channels;
DROP TABLE channels;
ALTER TABLE channels_new RENAME TO channels;
