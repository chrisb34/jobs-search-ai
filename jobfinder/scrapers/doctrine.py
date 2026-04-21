"""Doctrine careers scraper backed by Lever postings."""

from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass
from html import unescape
from typing import Any
from urllib.parse import urlparse

import requests
from bs4 import BeautifulSoup


USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36"
)
DOCTRINE_CAREERS_URL = "https://www.doctrine.fr/recrutement"
LEVER_POSTINGS_URL = "https://api.lever.co/v0/postings/doctrine?mode=json"


@dataclass
class DoctrineSearchConfig:
    search_url: str
    lever_company: str = "doctrine"

    @classmethod
    def from_search_url(cls, search_url: str) -> "DoctrineSearchConfig":
        parsed = urlparse(search_url)
        host = parsed.netloc.lower()
        if "jobs.lever.co" in host:
            path_parts = [part for part in parsed.path.split("/") if part]
            if path_parts:
                return cls(search_url=search_url, lever_company=path_parts[0])
        return cls(search_url=search_url)

    @property
    def postings_url(self) -> str:
        return f"https://api.lever.co/v0/postings/{self.lever_company}?mode=json"


class DoctrineScraper:
    source = "doctrine"

    def __init__(self, config: DoctrineSearchConfig, delay_seconds: float = 0.0) -> None:
        self.config = config
        self.delay_seconds = delay_seconds
        self.session = requests.Session()
        self.session.headers.update(
            {
                "User-Agent": USER_AGENT,
                "Accept": "application/json,text/plain,*/*",
                "Accept-Language": "fr-FR,fr;q=0.9,en;q=0.8",
                "Referer": DOCTRINE_CAREERS_URL,
            }
        )

    def scrape(self, max_pages: int = 1, auto_pages: bool = False) -> list[dict]:
        response = self.session.get(self.config.postings_url, timeout=30)
        response.raise_for_status()
        postings = response.json()
        return [self._build_raw_job(posting) for posting in postings]

    def _build_raw_job(self, posting: dict[str, Any]) -> dict[str, Any]:
        categories = posting.get("categories") or {}
        title = posting.get("text")
        company = "Doctrine"
        location_raw = categories.get("location")
        workplace_type = posting.get("workplaceType")
        additional_locations = categories.get("allLocations") or []
        commitment = categories.get("commitment")
        description_raw = self._build_description(posting)
        salary_raw = posting.get("salaryRange")
        hosted_url = posting.get("hostedUrl") or posting.get("applyUrl") or DOCTRINE_CAREERS_URL

        extra = {
            "country": posting.get("country"),
            "city": location_raw,
            "job_location": {
                "addressCountry": posting.get("country"),
                "addressLocality": location_raw,
            },
            "job_location_type": self._job_location_type(workplace_type),
            "salary": salary_raw,
            "language": self._infer_language(posting),
            "skills": self._skills(posting),
            "department": categories.get("team"),
            "all_locations": additional_locations,
            "lever_posting": {
                "id": posting.get("id"),
                "createdAt": posting.get("createdAt"),
                "hostedUrl": posting.get("hostedUrl"),
                "applyUrl": posting.get("applyUrl"),
                "workplaceType": workplace_type,
                "categories": categories,
            },
        }

        return {
            "source": self.source,
            "source_job_id": posting["id"],
            "url": hosted_url,
            "title": title,
            "company": company,
            "location_raw": location_raw,
            "description_raw": description_raw,
            "salary_raw": json.dumps(salary_raw, ensure_ascii=True, sort_keys=True) if salary_raw else None,
            "contract_raw": commitment,
            "remote_raw": workplace_type,
            "posted_at_raw": posting.get("createdAt"),
            "listed_at": posting.get("createdAt"),
            "content_hash": self._content_hash(posting, description_raw),
            "extra": extra,
        }

    def _build_description(self, posting: dict[str, Any]) -> str | None:
        sections = [
            posting.get("openingPlain"),
            posting.get("descriptionBodyPlain"),
            posting.get("descriptionPlain"),
            posting.get("additionalPlain"),
        ]
        lists = posting.get("lists") or []
        for item in lists:
            title = (item or {}).get("text")
            content = self._html_to_text((item or {}).get("content"))
            if title and content:
                sections.append(f"{title}\n{content}")
        text_sections = [section.strip() for section in sections if isinstance(section, str) and section.strip()]
        if text_sections:
            return "\n\n".join(text_sections)

        html_sections = [
            posting.get("opening"),
            posting.get("descriptionBody"),
            posting.get("description"),
            posting.get("additional"),
        ]
        parsed_sections = [self._html_to_text(section) for section in html_sections if section]
        parsed_sections = [section for section in parsed_sections if section]
        if parsed_sections:
            return "\n\n".join(parsed_sections)
        return None

    def _html_to_text(self, value: str | None) -> str | None:
        if not value:
            return None
        return BeautifulSoup(unescape(value), "html.parser").get_text("\n", strip=True)

    def _job_location_type(self, workplace_type: str | None) -> str | None:
        if not workplace_type:
            return None
        lowered = workplace_type.lower()
        if lowered == "remote":
            return "TELECOMMUTE"
        return None

    def _infer_language(self, posting: dict[str, Any]) -> str:
        description = " ".join(
            filter(
                None,
                [
                    posting.get("descriptionPlain"),
                    posting.get("openingPlain"),
                    posting.get("additionalPlain"),
                ],
            )
        ).lower()
        return "en" if "what awaits you if you join doctrine" in description else "fr"

    def _skills(self, posting: dict[str, Any]) -> list[str] | None:
        content = " ".join(
            filter(
                None,
                [
                    posting.get("descriptionBodyPlain"),
                    posting.get("descriptionPlain"),
                    posting.get("additionalPlain"),
                ],
            )
        )
        candidates = [
            "api",
            "aws",
            "backend",
            "docker",
            "gcp",
            "javascript",
            "kubernetes",
            "laravel",
            "nodejs",
            "postgresql",
            "python",
            "react",
            "salesforce",
            "snowflake",
            "sql",
            "symfony",
            "terraform",
            "typescript",
            "vue",
        ]
        lowered = content.lower()
        matches = [candidate for candidate in candidates if candidate in lowered]
        return matches or None

    def _content_hash(self, posting: dict[str, Any], description_raw: str | None) -> str:
        payload = {
            "id": posting.get("id"),
            "title": posting.get("text"),
            "team": (posting.get("categories") or {}).get("team"),
            "location": (posting.get("categories") or {}).get("location"),
            "commitment": (posting.get("categories") or {}).get("commitment"),
            "workplaceType": posting.get("workplaceType"),
            "description_raw": description_raw,
        }
        return hashlib.sha256(json.dumps(payload, ensure_ascii=True, sort_keys=True).encode("utf-8")).hexdigest()
