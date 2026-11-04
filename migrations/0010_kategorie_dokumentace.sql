-- F16: u objektu víc dokumentů podle kategorie (provozní, DR, incidenty, runbook, ostatní).
-- Dosavadní dokumentace se stane provozní dokumentací. Primární klíč se mění,
-- proto se tabulka přestaví (Migrator běží s vypnutými cizími klíči a poté je ověří).
CREATE TABLE object_docs_new (
    object_id TEXT NOT NULL REFERENCES objects (id) ON DELETE CASCADE,
    category TEXT NOT NULL DEFAULT 'provoz' CHECK (category IN ('provoz', 'dr', 'incidenty', 'runbook', 'ostatni')),
    markdown TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    updated_by TEXT NOT NULL,
    PRIMARY KEY (object_id, category)
);
INSERT INTO object_docs_new (object_id, category, markdown, updated_at, updated_by)
    SELECT object_id, 'provoz', markdown, updated_at, updated_by FROM object_docs;
DROP TABLE object_docs;
ALTER TABLE object_docs_new RENAME TO object_docs;

ALTER TABLE doc_revisions ADD COLUMN category TEXT NOT NULL DEFAULT 'provoz';
DROP INDEX idx_doc_revisions_object;
CREATE INDEX idx_doc_revisions_object ON doc_revisions (object_id, category, created_at);
