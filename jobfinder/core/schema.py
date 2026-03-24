"""SQLite schema definitions."""

SCHEMA_SQL = """
PRAGMA journal_mode = WAL;

CREATE TABLE IF NOT EXISTS scrape_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL,
    search_url TEXT NOT NULL,
    started_at TEXT NOT NULL,
    completed_at TEXT,
    status TEXT NOT NULL,
    jobs_seen INTEGER NOT NULL DEFAULT 0,
    error_message TEXT
);

CREATE TABLE IF NOT EXISTS raw_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL,
    source_job_id TEXT NOT NULL,
    url TEXT NOT NULL,
    title TEXT,
    company TEXT,
    location_raw TEXT,
    description_raw TEXT,
    salary_raw TEXT,
    contract_raw TEXT,
    remote_raw TEXT,
    posted_at_raw TEXT,
    listed_at TEXT,
    scraped_at TEXT NOT NULL,
    first_seen_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    missing_runs INTEGER NOT NULL DEFAULT 0,
    last_run_id INTEGER,
    seen_in_run INTEGER NOT NULL DEFAULT 0,
    content_hash TEXT NOT NULL,
    extra_json TEXT,
    UNIQUE(source, source_job_id),
    FOREIGN KEY(last_run_id) REFERENCES scrape_runs(id)
);

CREATE INDEX IF NOT EXISTS idx_raw_jobs_source_status
ON raw_jobs(source, status);

CREATE INDEX IF NOT EXISTS idx_raw_jobs_last_seen_at
ON raw_jobs(last_seen_at);

CREATE TABLE IF NOT EXISTS normalized_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL,
    source_job_id TEXT NOT NULL,
    canonical_job_key TEXT NOT NULL,
    title_normalized TEXT,
    company_normalized TEXT,
    country TEXT,
    city TEXT,
    remote_type TEXT,
    contract_type TEXT,
    salary_currency TEXT,
    salary_min REAL,
    salary_max REAL,
    seniority TEXT,
    language TEXT,
    tech_stack TEXT,
    ai_score REAL,
    ai_reason TEXT,
    ai_decision TEXT,
    updated_at TEXT NOT NULL,
    UNIQUE(source, source_job_id)
);

CREATE INDEX IF NOT EXISTS idx_normalized_jobs_key
ON normalized_jobs(canonical_job_key);

CREATE TABLE IF NOT EXISTS interesting_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL,
    source_job_id TEXT NOT NULL,
    canonical_job_key TEXT NOT NULL,
    title TEXT,
    company TEXT,
    url TEXT NOT NULL,
    location_raw TEXT,
    remote_type TEXT,
    contract_type TEXT,
    ai_score REAL,
    ai_reason TEXT,
    ai_decision TEXT NOT NULL,
    shortlist_status TEXT NOT NULL DEFAULT 'new',
    notes TEXT,
    description_snapshot TEXT,
    salary_snapshot TEXT,
    source_snapshot_json TEXT,
    snapshot_taken_at TEXT,
    promoted_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(source, source_job_id)
);

CREATE INDEX IF NOT EXISTS idx_interesting_jobs_decision
ON interesting_jobs(ai_decision, shortlist_status);
"""
