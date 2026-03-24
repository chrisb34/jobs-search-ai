# jobfinder

Initial MVP for a job search scraper and SQLite pipeline.

## Current scope

- LinkedIn public jobs search scraper
- Raw job storage in SQLite
- Central normalization step into a second table
- Run tracking and simple lifecycle status updates
- Saved search config via YAML
- Review/export command for active jobs
- Rule-based scoring from criteria config
- Shortlist promotion and review commands

## Quick start

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -e .
python -m jobfinder.runs.run_jobs \
  --search-url 'https://www.linkedin.com/jobs/search/?currentJobId=4386382859&f_WT=2&geoId=105015875&keywords=tech%20lead&origin=JOB_SEARCH_PAGE_JOB_FILTER&refresh=true' \
  --pages 3 \
  --auto-pages
```

The database will be created at `data/jobs.db`.

Run saved searches from config:

```bash
python -m jobfinder.runs.run_saved_searches --config config/sources.yaml --pages 3 --auto-pages
```

Review/export jobs:

```bash
python -m jobfinder.runs.export_jobs --limit 20 --format table
python -m jobfinder.runs.export_jobs --remote-only --format json --output data/remote_jobs.json
```

Score jobs:

```bash
python -m jobfinder.runs.score_jobs --criteria config/criteria.yaml --only-unscored
sqlite3 data/jobs.db "select title_normalized, ai_score, ai_decision from normalized_jobs order by ai_score desc limit 10;"
```

Promote shortlist jobs:

```bash
python -m jobfinder.runs.promote_shortlist --decisions high maybe
python -m jobfinder.runs.review_shortlist --status new --format table
```

Web review UI:

```bash
cd web
cp .env.example .env
composer install
php artisan serve
```

For Laravel Valet, link the `web` directory itself:

```bash
cd /Users/chrisbackhouse/Sites/jobs-ai/jobs-search-ai/web
valet link jobs-ai
```

The Laravel app reads the shared SQLite database at `../data/jobs.db` and exposes:

- `/interesting-jobs` for the shortlist table with filters
- `/interesting-jobs/{id}/edit` for notes and status updates

Cover letter generation:

- Add your API settings to `web/.env`:

```bash
OPENAI_API_KEY=...
OPENAI_MODEL=gpt-5-mini
OPENAI_BASE_URL=https://api.openai.com/v1
```

- Edit [applicant.php](/Users/chrisbackhouse/Sites/jobs-ai/jobs-search-ai/web/config/applicant.php) with your actual profile, skills, achievements, and tone preferences.
- In the Laravel UI, open a shortlisted job and use `Generate cover letter`.
- Generated drafts, model name, generation timestamp, and usage metadata are stored on `interesting_jobs`.

## Schema overview

`raw_jobs`

- Stores source-specific scraped data
- Keeps `first_seen_at`, `last_seen_at`, `status`, `missing_runs`, and `content_hash`

`normalized_jobs`

- Stores the centralized cleaned shape for later scoring and dedupe

`scrape_runs`

- Stores one record per run for traceability and lifecycle updates

`interesting_jobs`

- Stores promoted `high` and `maybe` jobs for ongoing review and application tracking
- Starts each promoted row with `shortlist_status = 'new'`
- Stores a durable local snapshot of the description, salary text, and raw source metadata at promotion time

## Notes

- This scraper uses LinkedIn's public guest job pages and is intentionally conservative.
- LinkedIn markup can change. Expect the scraper to need maintenance.
- The current normalization is basic and intended as a Phase 1 foundation.
- If a LinkedIn search URL includes `start=25`, `start=50`, and so on, the scraper now uses that as the base pagination offset.
- With `--auto-pages`, the scraper also loads the full LinkedIn search page, reads visible pager links when present, and computes page offsets in `25`-job steps.
- Rule-based scoring is configured in [config/criteria.yaml](/Users/chrisbackhouse/Sites/jobs-ai/jobs-search-ai/config/criteria.yaml) so you can tune preferences without changing Python code.
