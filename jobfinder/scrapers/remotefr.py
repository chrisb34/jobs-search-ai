"""RemoteFR jobs scraper."""

from __future__ import annotations

import hashlib
import html
import json
from dataclasses import dataclass
from typing import Iterator
from urllib.parse import parse_qs, urljoin, urlparse

import requests
from bs4 import BeautifulSoup


USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36"
)
REMOTEFR_BASE_URL = "https://remotefr.com"


@dataclass
class RemoteFrSearchConfig:
    search_url: str
    path: str
    page: int = 1

    @classmethod
    def from_search_url(cls, search_url: str) -> "RemoteFrSearchConfig":
        parsed = urlparse(search_url)
        params = parse_qs(parsed.query)
        page = max(int(params.get("page", ["1"])[0] or "1"), 1)
        return cls(
            search_url=search_url,
            path=parsed.path or "/",
            page=page,
        )


class RemoteFrScraper:
    source = "remotefr"

    def __init__(self, config: RemoteFrSearchConfig, delay_seconds: float = 0.5) -> None:
        self.config = config
        self.delay_seconds = delay_seconds
        self.session = requests.Session()
        self.session.headers.update(
            {
                "User-Agent": USER_AGENT,
                "Accept-Language": "fr-FR,fr;q=0.9,en;q=0.8",
            }
        )

    def scrape(self, max_pages: int = 1, auto_pages: bool = False) -> list[dict]:
        # RemoteFR category pages are currently single-page lists.
        listing_html = self._fetch(self.config.search_url)
        jobs: list[dict] = []
        seen_ids: set[str] = set()

        for card in self._parse_listing_cards(listing_html):
            if card["source_job_id"] in seen_ids:
                continue
            detail = self._fetch_job_detail(card["url"])
            jobs.append(self._build_raw_job(card, detail))
            seen_ids.add(card["source_job_id"])

        return jobs

    def _fetch(self, url: str) -> str:
        response = self.session.get(url, timeout=30)
        response.raise_for_status()
        return response.text

    def _parse_listing_cards(self, page_html: str) -> Iterator[dict]:
        soup = BeautifulSoup(page_html, "html.parser")
        for item in soup.select("ul[role='list'] > li"):
            main_link = item.select_one("a[href^='/emploi-teletravail/']")
            if main_link is None:
                continue
            href = main_link.get("href") or ""
            title_node = item.select_one("p.truncate")
            company_node = item.select_one("p.flex.items-center.text-sm.text-gray-600.font-medium")
            meta_nodes = item.select("p.mt-2.flex.items-center.text-sm.text-gray-500")
            time_node = item.select_one("time[datetime]")
            location_raw = meta_nodes[0].get_text(" ", strip=True) if len(meta_nodes) > 0 else None
            posted_at_raw = time_node.get_text(" ", strip=True) if time_node else None
            skill_tags = [
                tag.get_text(" ", strip=True)
                for tag in item.select("a.job_tag[href^='/competences/']")
            ]
            source_job_id = href.rstrip("/").split("/")[-1]

            yield {
                "source_job_id": source_job_id,
                "url": urljoin(REMOTEFR_BASE_URL, href),
                "title": title_node.get_text(" ", strip=True) if title_node else main_link.get_text(" ", strip=True),
                "company": company_node.get_text(" ", strip=True) if company_node else None,
                "location_raw": location_raw,
                "posted_at_raw": posted_at_raw,
                "listed_at": posted_at_raw,
                "skill_tags": skill_tags,
            }

    def _fetch_job_detail(self, url: str) -> dict:
        page_html = self._fetch(url)
        soup = BeautifulSoup(page_html, "html.parser")
        job_posting = self._extract_job_posting(soup)
        description_raw = self._extract_description(soup, job_posting)
        return {
            "description_raw": description_raw,
            "job_posting": job_posting,
        }

    def _extract_job_posting(self, soup: BeautifulSoup) -> dict:
        for node in soup.select('script[type="application/ld+json"]'):
            raw_text = node.string or node.get_text(strip=True)
            if not raw_text:
                continue
            try:
                parsed = json.loads(raw_text)
            except json.JSONDecodeError:
                continue
            if parsed.get("@type") == "JobPosting":
                return parsed
        return {}

    def _extract_description(self, soup: BeautifulSoup, job_posting: dict) -> str | None:
        prose = soup.select_one("div.prose")
        if prose:
            return prose.get_text("\n", strip=True)
        description = job_posting.get("description")
        if description:
            return BeautifulSoup(html.unescape(description), "html.parser").get_text("\n", strip=True)
        return None

    def _build_raw_job(self, card: dict, detail: dict) -> dict:
        job_posting = detail.get("job_posting") or {}
        hiring_organization = job_posting.get("hiringOrganization") or {}
        address = (hiring_organization.get("address") or {})
        salary = self._extract_salary_struct(job_posting)
        description_raw = detail.get("description_raw")
        location_raw = card.get("location_raw")
        city = None
        country = None
        if location_raw:
            parts = [part.strip() for part in location_raw.split(",") if part.strip()]
            if parts:
                city = parts[0] if len(parts) > 1 else None
                country = parts[-1]

        return {
            "source": self.source,
            "source_job_id": card["source_job_id"],
            "url": card["url"],
            "title": card.get("title"),
            "company": card.get("company") or hiring_organization.get("name"),
            "location_raw": location_raw,
            "description_raw": description_raw,
            "salary_raw": json.dumps(salary, ensure_ascii=True, sort_keys=True) if salary else None,
            "contract_raw": job_posting.get("employmentType"),
            "remote_raw": "remote",
            "posted_at_raw": card.get("posted_at_raw"),
            "listed_at": job_posting.get("datePosted") or card.get("listed_at"),
            "content_hash": self._content_hash(card, description_raw),
            "extra": {
                "country": self._country_code(country or address.get("addressCountry")),
                "city": city or address.get("addressLocality"),
                "job_location": {
                    "addressCountry": self._country_code(country or address.get("addressCountry")),
                    "addressLocality": city or address.get("addressLocality"),
                },
                "job_location_type": "TELECOMMUTE",
                "salary": salary,
                "language": "fr",
                "skills": card.get("skill_tags"),
                "job_posting": job_posting,
            },
        }

    def _extract_salary_struct(self, job_posting: dict) -> dict | None:
        base_salary = job_posting.get("baseSalary") or {}
        value = base_salary.get("value") or {}
        currency = base_salary.get("currency")
        minimum = value.get("minValue")
        maximum = value.get("maxValue")
        if currency is None and minimum is None and maximum is None:
            return None
        return {
            "currency": currency,
            "min": minimum,
            "max": maximum,
            "period": value.get("unitText"),
        }

    def _country_code(self, value: str | None) -> str | None:
        if not value:
            return None
        value = value.strip()
        if len(value) == 2:
            return value.upper()
        mapping = {
            "france": "FR",
            "monde": "WORLD",
            "world": "WORLD",
        }
        return mapping.get(value.lower(), value.upper())

    def _content_hash(self, card: dict, description_raw: str | None) -> str:
        payload = {
            "source_job_id": card["source_job_id"],
            "title": card.get("title"),
            "company": card.get("company"),
            "posted_at_raw": card.get("posted_at_raw"),
            "description_raw": description_raw,
        }
        return hashlib.sha256(json.dumps(payload, ensure_ascii=True, sort_keys=True).encode("utf-8")).hexdigest()
