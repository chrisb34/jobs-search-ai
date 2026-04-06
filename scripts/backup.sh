#!/bin/bash
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${PROJECT_ROOT}" || exit 1
cp web/config/applicant.local.php backups/
cp config/criteria.local.yaml backups/
cp data/jobs.db backups/