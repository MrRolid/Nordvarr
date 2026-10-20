-- F1: inventář (objekty, externí id, atributy, tagy, vztahy, dokumentace),
-- události a API tokeny.

CREATE TABLE objects (
    id TEXT PRIMARY KEY,
    type TEXT NOT NULL CHECK (type IN ('server', 'vm', 'service', 'website', 'database', 'domain', 'network_device', 'other')),
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    lifecycle TEXT NOT NULL DEFAULT 'active' CHECK (lifecycle IN ('active', 'archived')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX idx_objects_type ON objects (type);
CREATE INDEX idx_objects_name ON objects (name);

CREATE TABLE object_external_ids (
    object_id TEXT NOT NULL REFERENCES objects (id) ON DELETE CASCADE,
    source TEXT NOT NULL,
    external_id TEXT NOT NULL,
    PRIMARY KEY (source, external_id)
);

CREATE INDEX idx_object_external_ids_object ON object_external_ids (object_id);

-- is_json = 1: hodnota je JSON (pole, číslo, logická hodnota), jinak prostý text.
CREATE TABLE object_attributes (
    object_id TEXT NOT NULL REFERENCES objects (id) ON DELETE CASCADE,
    key TEXT NOT NULL,
    value TEXT NOT NULL,
    is_json INTEGER NOT NULL DEFAULT 0,
    source TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (object_id, key, source)
);

CREATE TABLE relationships (
    id TEXT PRIMARY KEY,
    from_id TEXT NOT NULL REFERENCES objects (id) ON DELETE CASCADE,
    to_id TEXT NOT NULL REFERENCES objects (id) ON DELETE CASCADE,
    kind TEXT NOT NULL CHECK (kind IN ('depends_on', 'contains')),
    note TEXT NOT NULL DEFAULT '',
    source TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (from_id, to_id, kind),
    CHECK (from_id <> to_id)
);

CREATE INDEX idx_relationships_to ON relationships (to_id);

CREATE TABLE tags (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL UNIQUE
);

CREATE TABLE object_tags (
    object_id TEXT NOT NULL REFERENCES objects (id) ON DELETE CASCADE,
    tag_id TEXT NOT NULL REFERENCES tags (id) ON DELETE CASCADE,
    PRIMARY KEY (object_id, tag_id)
);

CREATE INDEX idx_object_tags_tag ON object_tags (tag_id);

CREATE TABLE object_docs (
    object_id TEXT PRIMARY KEY REFERENCES objects (id) ON DELETE CASCADE,
    markdown TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    updated_by TEXT NOT NULL
);

-- Revize jsou neměnné, aplikace je jen přidává.
CREATE TABLE doc_revisions (
    id TEXT PRIMARY KEY,
    object_id TEXT NOT NULL REFERENCES objects (id) ON DELETE CASCADE,
    author TEXT NOT NULL,
    created_at TEXT NOT NULL,
    markdown TEXT NOT NULL
);

CREATE INDEX idx_doc_revisions_object ON doc_revisions (object_id, created_at);

CREATE TABLE events (
    id TEXT PRIMARY KEY,
    source TEXT NOT NULL,
    source_event_id TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('deploy', 'config', 'maintenance', 'restart', 'incident', 'note', 'other')),
    occurred_at TEXT NOT NULL,
    summary TEXT NOT NULL CHECK (length(summary) <= 300),
    details TEXT NOT NULL DEFAULT '' CHECK (length(details) <= 4000),
    created_at TEXT NOT NULL,
    UNIQUE (source, source_event_id)
);

CREATE INDEX idx_events_occurred ON events (occurred_at);

CREATE TABLE event_objects (
    event_id TEXT NOT NULL REFERENCES events (id) ON DELETE CASCADE,
    object_id TEXT NOT NULL REFERENCES objects (id) ON DELETE CASCADE,
    PRIMARY KEY (event_id, object_id)
);

CREATE INDEX idx_event_objects_object ON event_objects (object_id);

-- Token se ukládá jen jako prefix + HMAC-SHA256, nikdy v čitelné podobě.
CREATE TABLE api_tokens (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    prefix TEXT NOT NULL UNIQUE,
    hash TEXT NOT NULL,
    source TEXT NOT NULL,
    scopes TEXT NOT NULL,
    created_at TEXT NOT NULL,
    created_by TEXT,
    last_used_at TEXT,
    revoked_at TEXT
);

CREATE TABLE api_rate_limits (
    token_id TEXT NOT NULL REFERENCES api_tokens (id) ON DELETE CASCADE,
    window_start TEXT NOT NULL,
    count INTEGER NOT NULL,
    PRIMARY KEY (token_id, window_start)
);
