"""Storage helpers for scrape runs and job persistence."""

from __future__ import annotations

import json
import sqlite3
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path
from typing import Iterator

from jobfinder.core.schema import SCHEMA_SQL


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


@contextmanager
def connect(db_path: str | Path) -> Iterator[sqlite3.Connection]:
    conn = sqlite3.connect(str(db_path))
    conn.row_factory = sqlite3.Row
    try:
        yield conn
        conn.commit()
    finally:
        conn.close()


def init_db(conn: sqlite3.Connection) -> None:
    conn.executescript(SCHEMA_SQL)
    _ensure_interesting_jobs_columns(conn)


def _ensure_interesting_jobs_columns(conn: sqlite3.Connection) -> None:
    existing_columns = {
        row["name"]
        for row in conn.execute("PRAGMA table_info(interesting_jobs)")
    }
    required_columns = {
        "description_snapshot": "TEXT",
        "salary_snapshot": "TEXT",
        "source_snapshot_json": "TEXT",
        "snapshot_taken_at": "TEXT",
    }
    for column_name, column_type in required_columns.items():
        if column_name in existing_columns:
            continue
        conn.execute(
            f"ALTER TABLE interesting_jobs ADD COLUMN {column_name} {column_type}"
        )


def create_run(conn: sqlite3.Connection, source: str, search_url: str) -> int:
    started_at = utc_now()
    cursor = conn.execute(
        """
        INSERT INTO scrape_runs (source, search_url, started_at, status)
        VALUES (?, ?, ?, 'running')
        """,
        (source, search_url, started_at),
    )
    conn.execute("UPDATE raw_jobs SET seen_in_run = 0 WHERE source = ?", (source,))
    return int(cursor.lastrowid)


def finish_run(
    conn: sqlite3.Connection,
    run_id: int,
    source: str,
    jobs_seen: int,
    error_message: str | None = None,
) -> None:
    completed_at = utc_now()
    status = "failed" if error_message else "completed"
    conn.execute(
        """
        UPDATE scrape_runs
        SET completed_at = ?, status = ?, jobs_seen = ?, error_message = ?
        WHERE id = ?
        """,
        (completed_at, status, jobs_seen, error_message, run_id),
    )
    conn.execute(
        """
        UPDATE raw_jobs
        SET missing_runs = CASE WHEN seen_in_run = 1 THEN 0 ELSE missing_runs + 1 END,
            status = CASE
                WHEN seen_in_run = 1 THEN 'active'
                WHEN seen_in_run = 0 AND missing_runs + 1 >= 3 THEN 'closed'
                ELSE 'missing'
            END
        WHERE source = ?
        """,
        (source,),
    )


def upsert_raw_job(conn: sqlite3.Connection, run_id: int, job: dict) -> None:
    now = utc_now()
    conn.execute(
        """
        INSERT INTO raw_jobs (
            source, source_job_id, url, title, company, location_raw,
            description_raw, salary_raw, contract_raw, remote_raw,
            posted_at_raw, listed_at, scraped_at, first_seen_at, last_seen_at,
            status, missing_runs, last_run_id, seen_in_run, content_hash, extra_json
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 0, ?, 1, ?, ?)
        ON CONFLICT(source, source_job_id) DO UPDATE SET
            url = excluded.url,
            title = excluded.title,
            company = excluded.company,
            location_raw = excluded.location_raw,
            description_raw = excluded.description_raw,
            salary_raw = excluded.salary_raw,
            contract_raw = excluded.contract_raw,
            remote_raw = excluded.remote_raw,
            posted_at_raw = excluded.posted_at_raw,
            listed_at = excluded.listed_at,
            scraped_at = excluded.scraped_at,
            last_seen_at = excluded.last_seen_at,
            status = 'active',
            missing_runs = 0,
            last_run_id = excluded.last_run_id,
            seen_in_run = 1,
            content_hash = excluded.content_hash,
            extra_json = excluded.extra_json
        """,
        (
            job["source"],
            job["source_job_id"],
            job["url"],
            job.get("title"),
            job.get("company"),
            job.get("location_raw"),
            job.get("description_raw"),
            job.get("salary_raw"),
            job.get("contract_raw"),
            job.get("remote_raw"),
            job.get("posted_at_raw"),
            job.get("listed_at"),
            now,
            now,
            now,
            run_id,
            job["content_hash"],
            json.dumps(job.get("extra", {}), ensure_ascii=True, sort_keys=True),
        ),
    )


def upsert_normalized_job(conn: sqlite3.Connection, normalized_job: dict) -> None:
    conn.execute(
        """
        INSERT INTO normalized_jobs (
            source, source_job_id, canonical_job_key, title_normalized,
            company_normalized, country, city, remote_type, contract_type,
            salary_currency, salary_min, salary_max, seniority, language,
            tech_stack, ai_score, ai_reason, ai_decision, updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(source, source_job_id) DO UPDATE SET
            canonical_job_key = excluded.canonical_job_key,
            title_normalized = excluded.title_normalized,
            company_normalized = excluded.company_normalized,
            country = excluded.country,
            city = excluded.city,
            remote_type = excluded.remote_type,
            contract_type = excluded.contract_type,
            salary_currency = excluded.salary_currency,
            salary_min = excluded.salary_min,
            salary_max = excluded.salary_max,
            seniority = excluded.seniority,
            language = excluded.language,
            tech_stack = excluded.tech_stack,
            ai_score = excluded.ai_score,
            ai_reason = excluded.ai_reason,
            ai_decision = excluded.ai_decision,
            updated_at = excluded.updated_at
        """,
        (
            normalized_job["source"],
            normalized_job["source_job_id"],
            normalized_job["canonical_job_key"],
            normalized_job.get("title_normalized"),
            normalized_job.get("company_normalized"),
            normalized_job.get("country"),
            normalized_job.get("city"),
            normalized_job.get("remote_type"),
            normalized_job.get("contract_type"),
            normalized_job.get("salary_currency"),
            normalized_job.get("salary_min"),
            normalized_job.get("salary_max"),
            normalized_job.get("seniority"),
            normalized_job.get("language"),
            normalized_job.get("tech_stack"),
            normalized_job.get("ai_score"),
            normalized_job.get("ai_reason"),
            normalized_job.get("ai_decision"),
            utc_now(),
        ),
    )


def query_review_jobs(
    conn: sqlite3.Connection,
    *,
    status: str = "active",
    limit: int = 50,
    remote_only: bool = False,
) -> list[sqlite3.Row]:
    where_clauses = ["r.status = ?"]
    params: list[object] = [status]

    if remote_only:
        where_clauses.append("n.remote_type = 'remote'")

    params.append(limit)
    sql = f"""
        SELECT
            r.source,
            r.source_job_id,
            r.title,
            r.company,
            r.location_raw,
            r.url,
            r.posted_at_raw,
            r.last_seen_at,
            n.remote_type,
            n.contract_type,
            n.city,
            n.country,
            n.canonical_job_key
        FROM raw_jobs r
        LEFT JOIN normalized_jobs n
            ON n.source = r.source AND n.source_job_id = r.source_job_id
        WHERE {' AND '.join(where_clauses)}
        ORDER BY r.last_seen_at DESC
        LIMIT ?
    """
    return list(conn.execute(sql, params))


def query_jobs_for_scoring(
    conn: sqlite3.Connection,
    *,
    status: str = "active",
    limit: int = 200,
    only_unscored: bool = False,
) -> list[sqlite3.Row]:
    where_clauses = ["r.status = ?"]
    params: list[object] = [status]
    if only_unscored:
        where_clauses.append("n.ai_decision IS NULL")
    params.append(limit)
    sql = f"""
        SELECT
            r.source,
            r.source_job_id,
            r.title,
            r.company,
            r.location_raw,
            r.description_raw,
            n.title_normalized,
            n.company_normalized,
            n.country,
            n.city,
            n.remote_type,
            n.contract_type,
            n.seniority,
            n.tech_stack
        FROM raw_jobs r
        INNER JOIN normalized_jobs n
            ON n.source = r.source AND n.source_job_id = r.source_job_id
        WHERE {' AND '.join(where_clauses)}
        ORDER BY r.last_seen_at DESC
        LIMIT ?
    """
    return list(conn.execute(sql, params))


def update_job_score(
    conn: sqlite3.Connection,
    *,
    source: str,
    source_job_id: str,
    score: float,
    decision: str,
    reason: str,
) -> None:
    conn.execute(
        """
        UPDATE normalized_jobs
        SET ai_score = ?, ai_reason = ?, ai_decision = ?, updated_at = ?
        WHERE source = ? AND source_job_id = ?
        """,
        (score, reason, decision, utc_now(), source, source_job_id),
    )


def query_jobs_for_shortlist(
    conn: sqlite3.Connection,
    *,
    decisions: list[str],
    status: str = "active",
    limit: int = 200,
) -> list[sqlite3.Row]:
    placeholders = ", ".join("?" for _ in decisions)
    params: list[object] = [status, *decisions, limit]
    sql = f"""
        SELECT
            r.source,
            r.source_job_id,
            r.title,
            r.company,
            r.url,
            r.location_raw,
            r.description_raw,
            r.salary_raw,
            r.extra_json,
            n.canonical_job_key,
            n.remote_type,
            n.contract_type,
            n.ai_score,
            n.ai_reason,
            n.ai_decision
        FROM raw_jobs r
        INNER JOIN normalized_jobs n
            ON n.source = r.source AND n.source_job_id = r.source_job_id
        WHERE r.status = ?
          AND n.ai_decision IN ({placeholders})
        ORDER BY n.ai_score DESC, r.last_seen_at DESC
        LIMIT ?
    """
    return list(conn.execute(sql, params))


def upsert_interesting_job(conn: sqlite3.Connection, row: sqlite3.Row) -> None:
    now = utc_now()
    conn.execute(
        """
        INSERT INTO interesting_jobs (
            source, source_job_id, canonical_job_key, title, company, url,
            location_raw, remote_type, contract_type, ai_score, ai_reason,
            ai_decision, shortlist_status, notes, description_snapshot,
            salary_snapshot, source_snapshot_json, snapshot_taken_at,
            promoted_at, updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', NULL, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(source, source_job_id) DO UPDATE SET
            canonical_job_key = excluded.canonical_job_key,
            title = excluded.title,
            company = excluded.company,
            url = excluded.url,
            location_raw = excluded.location_raw,
            remote_type = excluded.remote_type,
            contract_type = excluded.contract_type,
            ai_score = excluded.ai_score,
            ai_reason = excluded.ai_reason,
            ai_decision = excluded.ai_decision,
            description_snapshot = CASE
                WHEN interesting_jobs.shortlist_status IN ('new', 'reviewing')
                    OR interesting_jobs.description_snapshot IS NULL
                THEN excluded.description_snapshot
                ELSE interesting_jobs.description_snapshot
            END,
            salary_snapshot = CASE
                WHEN interesting_jobs.shortlist_status IN ('new', 'reviewing')
                    OR interesting_jobs.salary_snapshot IS NULL
                THEN excluded.salary_snapshot
                ELSE interesting_jobs.salary_snapshot
            END,
            source_snapshot_json = CASE
                WHEN interesting_jobs.shortlist_status IN ('new', 'reviewing')
                    OR interesting_jobs.source_snapshot_json IS NULL
                THEN excluded.source_snapshot_json
                ELSE interesting_jobs.source_snapshot_json
            END,
            snapshot_taken_at = CASE
                WHEN interesting_jobs.shortlist_status IN ('new', 'reviewing')
                    OR interesting_jobs.snapshot_taken_at IS NULL
                THEN excluded.snapshot_taken_at
                ELSE interesting_jobs.snapshot_taken_at
            END,
            updated_at = excluded.updated_at
        """,
        (
            row["source"],
            row["source_job_id"],
            row["canonical_job_key"],
            row["title"],
            row["company"],
            row["url"],
            row["location_raw"],
            row["remote_type"],
            row["contract_type"],
            row["ai_score"],
            row["ai_reason"],
            row["ai_decision"],
            row["description_raw"],
            row["salary_raw"],
            row["extra_json"],
            now,
            now,
            now,
        ),
    )


def query_interesting_jobs(
    conn: sqlite3.Connection,
    *,
    shortlist_status: str | None = None,
    decision: str | None = None,
    limit: int = 50,
) -> list[sqlite3.Row]:
    where_clauses: list[str] = []
    params: list[object] = []
    if shortlist_status:
        where_clauses.append("shortlist_status = ?")
        params.append(shortlist_status)
    if decision:
        where_clauses.append("ai_decision = ?")
        params.append(decision)
    params.append(limit)

    where_sql = ""
    if where_clauses:
        where_sql = "WHERE " + " AND ".join(where_clauses)

    sql = f"""
        SELECT
            source,
            source_job_id,
            title,
            company,
            location_raw,
            remote_type,
            ai_score,
            ai_decision,
            shortlist_status,
            description_snapshot,
            salary_snapshot,
            source_snapshot_json,
            snapshot_taken_at,
            notes,
            url,
            updated_at
        FROM interesting_jobs
        {where_sql}
        ORDER BY ai_score DESC, updated_at DESC
        LIMIT ?
    """
    return list(conn.execute(sql, params))
