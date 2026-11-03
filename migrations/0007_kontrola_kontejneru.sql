-- F13: typ kontroly "container" (stav kontejneru Dockeru z hlášení agenta).

-- Typ kontroly je v CHECK omezení; SQLite ho neumí změnit, tabulka se přestaví (stejně jako v 0005).
-- Migrator spouští migrace s vypnutými cizími klíči, takže DROP TABLE nesmaže výsledky ani alerty.
CREATE TABLE checks_new (
    id TEXT PRIMARY KEY,
    object_id TEXT NOT NULL REFERENCES objects (id) ON DELETE CASCADE,
    type TEXT NOT NULL CHECK (type IN ('http', 'tcp', 'ping', 'tls_expiry', 'domain_expiry', 'heartbeat', 'agent', 'container')),
    name TEXT NOT NULL DEFAULT '',
    config TEXT NOT NULL,
    interval_s INTEGER NOT NULL CHECK (interval_s >= 60),
    timeout_s INTEGER NOT NULL CHECK (timeout_s BETWEEN 1 AND 30),
    failure_threshold INTEGER NOT NULL DEFAULT 2 CHECK (failure_threshold BETWEEN 1 AND 10),
    enabled INTEGER NOT NULL DEFAULT 1,
    notify_on_degraded INTEGER NOT NULL DEFAULT 0,
    heartbeat_token TEXT UNIQUE,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
INSERT INTO checks_new SELECT id, object_id, type, name, config, interval_s, timeout_s, failure_threshold, enabled,
    notify_on_degraded, heartbeat_token, created_at, updated_at FROM checks;
DROP TABLE checks;
ALTER TABLE checks_new RENAME TO checks;
CREATE INDEX idx_checks_object ON checks (object_id);
