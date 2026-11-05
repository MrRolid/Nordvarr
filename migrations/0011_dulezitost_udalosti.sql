-- F21: události ze serverů. Důležitost (info, notice, warning, critical) a nové typy:
-- security (fail2ban, CrowdSec), business (objednávka, formulář podpory), message (vzkaz,
-- třeba od AI agenta na serveru). Typ je v CHECK omezení, tabulka se přestaví
-- (Migrator běží s vypnutými cizími klíči a poté je ověří).
CREATE TABLE events_new (
    id TEXT PRIMARY KEY,
    source TEXT NOT NULL,
    source_event_id TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('deploy', 'config', 'maintenance', 'restart', 'incident', 'note', 'other', 'security', 'business', 'message')),
    severity TEXT NOT NULL DEFAULT 'info' CHECK (severity IN ('info', 'notice', 'warning', 'critical')),
    occurred_at TEXT NOT NULL,
    summary TEXT NOT NULL CHECK (length(summary) <= 300),
    details TEXT NOT NULL DEFAULT '' CHECK (length(details) <= 4000),
    created_at TEXT NOT NULL,
    UNIQUE (source, source_event_id)
);
INSERT INTO events_new (id, source, source_event_id, type, severity, occurred_at, summary, details, created_at)
    SELECT id, source, source_event_id, type, CASE WHEN type = 'incident' THEN 'warning' ELSE 'info' END, occurred_at, summary, details, created_at FROM events;
DROP TABLE events;
ALTER TABLE events_new RENAME TO events;
CREATE INDEX idx_events_occurred ON events (occurred_at);
CREATE INDEX idx_events_severity ON events (severity, occurred_at);
