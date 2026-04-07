#!/bin/bash
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${PROJECT_ROOT}" || exit 1
cp backups/applicant.local.php web/config/applicant.local.php
cp backups/criteria.local.yaml config/criteria.local.yaml 