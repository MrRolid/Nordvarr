-- F3: alerty, notifikační kanály, fronta odesílání, údržbová okna.

-- Snímek kontroly (typ, název, důvod) zůstává v alertu i po smazání kontroly.
CREATE TABLE alerts (
    id TEXT PRIMARY KEY,
    check_id TEXT REFERENCES checks (id) ON DELETE SET NULL,
    object_id TEXT NOT NULL REFERENCES objects (id) ON DELETE CASCADE,
    status TEXT NOT NULL CHECK (status IN ('open', 'resolved')),
    level TEXT NOT NULL CHECK (level IN ('unhealthy', 'degraded', 'unknown')),
    check_type TEXT NOT NULL,
    check_name TEXT NOT NULL DEFAULT '',
    reason_code TEXT,
    reason_detail TEXT NOT NULL DEFAULT '',
    opened_at TEXT NOT NULL,
    resolved_at TEXT,
    resolved_reason TEXT,
    last_notified_at TEXT,
    suppressed_by_alert_id TEXT REFERENCES alerts (id) ON DELETE SET NULL,
    probable_cause_object_id TEXT REFERENCES objects (id) ON DELETE SET NULL,
    maintenance_suppressed INTEGER NOT NULL DEFAULT 0
);

-- Na jednu kontrolu je otevřený nejvýš jeden alert.
CREATE UNIQUE INDEX idx_alerts_open_check ON alerts (check_id) WHERE status = 'open';
CREATE INDEX idx_alerts_status ON alerts (status, opened_at);
CREATE INDEX idx_alerts_object ON alerts (object_id, opened_at);

-- Citlivé položky konfigurace (hesla, tokeny, tajemství) jsou zašifrované tajemstvím aplikace.
CREATE TABLE channels (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('email', 'telegram', 'webhook')),
    config TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

-- Bez pravidel dostává kanál vše, jinak jen objekty s některým z tagů.
CREATE TABLE channel_rules (
    channel_id TEXT NOT NULL REFERENCES channels (id) ON DELETE CASCADE,
    tag TEXT NOT NULL,
    PRIMARY KEY (channel_id, tag)
);

CREATE TABLE deliveries (
    id TEXT PRIMARY KEY,
    channel_id TEXT NOT NULL REFERENCES channels (id) ON DELETE CASCADE,
    alert_id TEXT REFERENCES alerts (id) ON DELETE SET NULL,
    kind TEXT NOT NULL CHECK (kind IN ('opened', 'reminder', 'resolved', 'after_maintenance', 'unsuppressed', 'test')),
    subject TEXT NOT NULL,
    body TEXT NOT NULL,
    payload TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('pending', 'sent', 'failed')),
    attempts INTEGER NOT NULL DEFAULT 0,
    next_attempt_at TEXT,
    last_error TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    sent_at TEXT
);

CREATE INDEX idx_deliveries_pending ON deliveries (status, next_attempt_at);
CREATE INDEX idx_deliveries_alert ON deliveries (alert_id);
CREATE INDEX idx_deliveries_channel ON deliveries (channel_id, created_at);

CREATE TABLE delivery_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    delivery_id TEXT NOT NULL REFERENCES deliveries (id) ON DELETE CASCADE,
    attempted_at TEXT NOT NULL,
    ok INTEGER NOT NULL,
    error TEXT NOT NULL DEFAULT ''
);

CREATE INDEX idx_delivery_attempts_delivery ON delivery_attempts (delivery_id);

-- Rozsah je buď objekt, nebo tag.
CREATE TABLE maintenance_windows (
    id TEXT PRIMARY KEY,
    object_id TEXT REFERENCES objects (id) ON DELETE CASCADE,
    tag TEXT,
    starts_at TEXT NOT NULL,
    ends_at TEXT NOT NULL,
    pause_checks INTEGER NOT NULL DEFAULT 0,
    note TEXT NOT NULL DEFAULT '',
    created_by TEXT,
    created_at TEXT NOT NULL,
    CHECK ((object_id IS NULL) <> (tag IS NULL)),
    CHECK (ends_at > starts_at)
);

CREATE INDEX idx_maintenance_windows_time ON maintenance_windows (ends_at, starts_at);
