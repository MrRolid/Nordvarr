-- F6: hostový agent (metriky serverů, SMART) a typ kontroly "agent".

-- Typ kontroly je v CHECK omezení; SQLite ho neumí změnit, tabulka se přestaví.
-- Migrator spouští migrace s vypnutými cizími klíči, takže DROP TABLE nesmaže výsledky ani alerty.
CREATE TABLE checks_new (
    id TEXT PRIMARY KEY,
    object_id TEXT NOT NULL REFERENCES objects (id) ON DELETE CASCADE,
    type TEXT NOT NULL CHECK (type IN ('http', 'tcp', 'ping', 'tls_expiry', 'domain_expiry', 'heartbeat', 'agent')),
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

-- Server s agentem: poslední hlášení a poslední stav disků (SMART).
CREATE TABLE agent_hosts (
    object_id TEXT PRIMARY KEY REFERENCES objects (id) ON DELETE CASCADE,
    hostname TEXT NOT NULL,
    agent_version TEXT NOT NULL DEFAULT '',
    last_report_at TEXT NOT NULL,
    snapshot TEXT NOT NULL,
    smart TEXT NOT NULL DEFAULT '[]',
    smart_at TEXT,
    smart_error TEXT NOT NULL DEFAULT ''
);

-- Vzorky z hlášení (jeden řádek na hlášení), retence 48 h.
CREATE TABLE agent_samples (
    object_id TEXT NOT NULL REFERENCES objects (id) ON DELETE CASCADE,
    at TEXT NOT NULL,
    cpu_pct REAL,
    mem_pct REAL,
    swap_pct REAL,
    load1 REAL,
    disk_max_pct REAL,
    temp_max_c REAL,
    gpu_util_max_pct REAL,
    gpu_temp_max_c REAL,
    net_rx_bps REAL,
    net_tx_bps REAL,
    PRIMARY KEY (object_id, at)
);

CREATE INDEX idx_agent_samples_at ON agent_samples (at);

-- Hodinové agregace vzorků, retence 400 dní.
CREATE TABLE agent_hourly (
    object_id TEXT NOT NULL REFERENCES objects (id) ON DELETE CASCADE,
    hour TEXT NOT NULL,
    count INTEGER NOT NULL,
    cpu_avg REAL,
    cpu_max REAL,
    mem_avg REAL,
    mem_max REAL,
    disk_max REAL,
    temp_max REAL,
    gpu_util_avg REAL,
    gpu_util_max REAL,
    gpu_temp_max REAL,
    net_rx_avg REAL,
    net_tx_avg REAL,
    PRIMARY KEY (object_id, hour)
);

CREATE INDEX idx_agent_hourly_hour ON agent_hourly (hour);
