-- F11: typy objektů "container" (kontejner) a "cluster" (Kubernetes, Docker Swarm a podobně).

-- Typ objektu je v CHECK omezení; SQLite ho neumí změnit, tabulka se přestaví.
-- Migrator spouští migrace s vypnutými cizími klíči, takže DROP TABLE nesmaže atributy,
-- vztahy, kontroly ani historii; po migraci ověří PRAGMA foreign_key_check.
CREATE TABLE objects_new (
    id TEXT PRIMARY KEY,
    type TEXT NOT NULL CHECK (type IN ('server', 'vm', 'cluster', 'container', 'service', 'website', 'database', 'domain', 'network_device', 'other')),
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    lifecycle TEXT NOT NULL DEFAULT 'active' CHECK (lifecycle IN ('active', 'archived')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
INSERT INTO objects_new SELECT id, type, name, slug, description, lifecycle, created_at, updated_at FROM objects;
DROP TABLE objects;
ALTER TABLE objects_new RENAME TO objects;
CREATE INDEX idx_objects_type ON objects (type);
CREATE INDEX idx_objects_name ON objects (name);
