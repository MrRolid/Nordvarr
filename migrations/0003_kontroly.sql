-- F2: kontroly, jejich výsledky, hodinové agregace a aktuální stav.

CREATE TABLE checks (
    id TEXT PRIMARY KEY,
    object_id TEXT NOT NULL REFERENCES objects (id) ON DELETE CASCADE,
    type TEXT NOT NULL CHECK (type IN ('http', 'tcp', 'ping', 'tls_expiry', 'domain_expiry', 'heartbeat')),
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

CREATE INDEX idx_checks_object ON checks (object_id);

-- Syrové výsledky, retence 14 dní.
CREATE TABLE check_results (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    check_id TEXT NOT NULL REFERENCES checks (id) ON DELETE CASCADE,
    started_at TEXT NOT NULL,
    finished_at TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('healthy', 'degraded', 'unhealthy', 'unknown')),
    reason_code TEXT,
    reason_detail TEXT NOT NULL DEFAULT '',
    latency_ms INTEGER
);

CREATE INDEX idx_check_results_check ON check_results (check_id, started_at);
CREATE INDEX idx_check_results_started ON check_results (started_at);

-- Hodinové agregace, retence 400 dní. hour = začátek hodiny v UTC.
CREATE TABLE check_hourly (
    check_id TEXT NOT NULL REFERENCES checks (id) ON DELETE CASCADE,
    hour TEXT NOT NULL,
    count INTEGER NOT NULL,
    failures INTEGER NOT NULL,
    avg_latency_ms INTEGER,
    max_latency_ms INTEGER,
    PRIMARY KEY (check_id, hour)
);

CREATE INDEX idx_check_hourly_hour ON check_hourly (hour);

-- Aktuální stav kontroly. last_result_at = začátek posledního započítaného výsledku.
CREATE TABLE check_state (
    check_id TEXT PRIMARY KEY REFERENCES checks (id) ON DELETE CASCADE,
    status TEXT NOT NULL DEFAULT 'unknown' CHECK (status IN ('healthy', 'degraded', 'unhealthy', 'unknown')),
    reason_code TEXT,
    reason_detail TEXT NOT NULL DEFAULT '',
    since TEXT NOT NULL,
    last_run_at TEXT,
    next_run_at TEXT,
    consecutive_failures INTEGER NOT NULL DEFAULT 0,
    last_result_at TEXT,
    last_result_status TEXT,
    last_latency_ms INTEGER,
    last_ping_at TEXT,
    expires_at TEXT
);

CREATE INDEX idx_check_state_next ON check_state (next_run_at);

-- Cache HTTP odpovědí (bootstrap RDAP od IANA).
CREATE TABLE http_cache (
    url TEXT PRIMARY KEY,
    fetched_at TEXT NOT NULL,
    body TEXT NOT NULL
);
