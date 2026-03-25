# jobs-search-ai

Job search scraper and review system built around Python scrapers, SQLite storage, rule-based scoring, and a small Laravel admin UI.

It is designed as a practical local-first workflow:

- scrape jobs from multiple sources
- store raw and normalized records in SQLite
- score them against configurable criteria
- promote good matches into a shortlist
- review and edit that shortlist in a browser
- optionally generate cover-letter drafts from shortlisted jobs

## Current sources

- LinkedIn public jobs search pages
- Welcome to the Jungle
- RemoteFR
- EnglishJobs

## Stack

- Python 3 for scraping, normalization, scoring, and lifecycle commands
- SQLite for storage
- Laravel + Blade for the review UI
- OpenAI API optional for cover-letter generation

## Project structure

```text
jobs-search-ai/
  config/
    criteria.yaml
    sources.yaml
  data/
    jobs.db
  jobfinder/
    core/
    runs/
    scrapers/
  web/
    app/
    config/
    resources/
```

## Requirements

### Core app

- Python 3.9+
- `sqlite3`

### Web UI

- PHP 8.2+
- Composer
- Laravel Valet optional but convenient on macOS

## Python setup

```bash
cd /Users/chrisbackhouse/Sites/jobs-ai/jobs-search-ai
python3 -m venv .venv
source .venv/bin/activate
pip install -e .
```

The main database lives at [data/jobs.db](/Users/chrisbackhouse/Sites/jobs-ai/jobs-search-ai/data/jobs.db).

## Laravel setup

```bash
cd /Users/chrisbackhouse/Sites/jobs-ai/jobs-search-ai/web
cp .env.example .env
composer install
php artisan key:generate
```

The Laravel app reads the shared SQLite database at `../data/jobs.db`.

If you use Valet:

```bash
cd /Users/chrisbackhouse/Sites/jobs-ai/jobs-search-ai/web
valet link jobs-ai
```

Then open `http://jobs-ai.test`.

If you do not use Valet:

```bash
cd /Users/chrisbackhouse/Sites/jobs-ai/jobs-search-ai/web
php artisan serve
```

## Config files

### Saved searches

Edit [sources.yaml](/Users/chrisbackhouse/Sites/jobs-ai/jobs-search-ai/config/sources.yaml) to define the search URLs you want to scrape.

Each entry has:

- `name`
- `source`
- `url`

Example:

```yaml
- name: linkedin-tech-lead
  source: linkedin
  url: https://www.linkedin.com/jobs/search/?keywords=tech%20lead
```

### Scoring criteria

Edit [criteria.yaml](/Users/chrisbackhouse/Sites/jobs-ai/jobs-search-ai/config/criteria.yaml) to tune:

- desired title keywords
- desired tech keywords
- remote and contract preferences
- preferred locations
- excluded keywords
- language penalties
- scoring thresholds

The checked-in file is intentionally generic. Treat it as a starting point, not a final profile.

### Cover-letter profile

Edit [applicant.php](/Users/chrisbackhouse/Sites/jobs-ai/jobs-search-ai/web/config/applicant.php) with your own:

- name and location
- summary
- skills
- achievements
- tone
- constraints

The checked-in version is a template and should be replaced with your own details before using cover-letter generation.

## Main commands

### Run a single scrape

```bash
cd /Users/chrisbackhouse/Sites/jobs-ai/jobs-search-ai
python3 -m jobfinder.runs.run_jobs \
  --search-url 'https://www.linkedin.com/jobs/search/?keywords=tech%20lead' \
  --pages 3 \
  --auto-pages
```

### Run saved searches

```bash
python3 -m jobfinder.runs.run_saved_searches \
  --config config/sources.yaml \
  --pages 3 \
  --auto-pages
```

Run one saved search only:

```bash
python3 -m jobfinder.runs.run_saved_searches \
  --config config/sources.yaml \
  --search-name englishjobs-remote
```

### Score jobs

```bash
python3 -m jobfinder.runs.score_jobs \
  --criteria config/criteria.yaml \
  --only-unscored
```

### Promote shortlist jobs

```bash
python3 -m jobfinder.runs.promote_shortlist --decisions high maybe
```

### Review shortlist in terminal

```bash
python3 -m jobfinder.runs.review_shortlist --status new --format table
```

### Diagnose shortlist drift

```bash
python3 -m jobfinder.runs.diagnose_shortlist --limit 20
```

## Web UI

Useful pages:

- `/interesting-jobs`
- `/interesting-jobs/{id}/edit`
- `/console`
- `/config-editor`

The UI supports:

- shortlist filters
- source visibility
- notes and status updates
- probable duplicate warnings
- manual command execution
- editing `sources.yaml` and `criteria.yaml`

## OpenAI setup

Cover-letter generation is optional.

Add these to [web/.env](/Users/chrisbackhouse/Sites/jobs-ai/jobs-search-ai/web/.env):

```bash
OPENAI_API_KEY=your_key_here
OPENAI_MODEL=gpt-5-mini
OPENAI_BASE_URL=https://api.openai.com/v1
```

Then update [applicant.php](/Users/chrisbackhouse/Sites/jobs-ai/jobs-search-ai/web/config/applicant.php) with your own details.

Generated drafts are stored in the `interesting_jobs` table.

## SQLite access

Terminal:

```bash
cd /Users/chrisbackhouse/Sites/jobs-ai/jobs-search-ai
sqlite3 data/jobs.db
```

Useful queries:

```sql
.tables
select count(*) from raw_jobs;
select count(*) from normalized_jobs;
select count(*) from interesting_jobs;
select title, company, ai_score, ai_decision from interesting_jobs order by ai_score desc limit 20;
```

You can also open [jobs.db](/Users/chrisbackhouse/Sites/jobs-ai/jobs-search-ai/data/jobs.db) with DBeaver or DB Browser for SQLite.

## macOS automation

macOS does have `cron`, but the native approach is `launchd`.

For this project, `launchd` is the better choice because it is the standard scheduler on macOS and plays better with user sessions and local services.

Typical approach:

1. Create a small shell script that activates your virtualenv and runs the pipeline.
2. Add a `LaunchAgent` plist in `~/Library/LaunchAgents/`.
3. Load it with `launchctl load`.

Example script:

```bash
#!/bin/bash
cd /Users/chrisbackhouse/Sites/jobs-ai/jobs-search-ai || exit 1
source .venv/bin/activate
python3 -m jobfinder.runs.run_saved_searches --config config/sources.yaml --pages 3 --auto-pages
python3 -m jobfinder.runs.score_jobs --criteria config/criteria.yaml
python3 -m jobfinder.runs.promote_shortlist --decisions high maybe
```

If you want a UI-driven scheduler later, the next sensible step is to move the current console actions to background jobs first.

## Notes

- Scrapers depend on third-party markup and APIs and will need maintenance over time.
- The repository ships with demo search sources, generic scoring defaults, and a template applicant profile.
- Do not commit your personal API keys or private profile details.
