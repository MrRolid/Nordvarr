-- F15: poslední řádky logů služeb z hlášení agenta (posílá je jednou za 10 minut).
ALTER TABLE agent_hosts ADD COLUMN logs TEXT NOT NULL DEFAULT '[]';
ALTER TABLE agent_hosts ADD COLUMN logs_at TEXT;
