import json
import re
import sys
import urllib.parse
import urllib.request
from html.parser import HTMLParser

BASE = "https://www.comelec.gov.ph/"
INDEX = BASE + "html-tpls/2025NLE/CLC2025.html"


class Links(HTMLParser):
    def __init__(self):
        super().__init__()
        self.links = []
        self.current = None

    def handle_starttag(self, tag, attrs):
        if tag == "a":
            self.current = dict(attrs)
            self.current["text"] = ""

    def handle_data(self, data):
        if self.current is not None:
            self.current["text"] += data

    def handle_endtag(self, tag):
        if tag == "a" and self.current is not None:
            self.current["text"] = " ".join(self.current["text"].split())
            self.links.append(self.current)
            self.current = None


def fetch(url):
    request = urllib.request.Request(
        url,
        headers={"User-Agent": "Mozilla/5.0", "Referer": BASE},
    )
    with urllib.request.urlopen(request, timeout=30) as response:
        return response.read().decode("utf-8", errors="replace")


def links(url):
    parser = Links()
    parser.feed(fetch(url))
    return parser.links


def pdf_url(href):
    return urllib.parse.urljoin(BASE, urllib.parse.quote(href, safe="/:?=&%"))


def main():
    index_links = links(INDEX)
    regions = []
    sources = []

    for link in index_links:
        href = link.get("href", "")
        if href.endswith(".pdf") and "CLC2025_" in href:
            sources.append({"region": "NATIONAL", "label": link["text"], "url": pdf_url(href), "index_url": INDEX})
        match = re.search(r"\?r=2025NLE/CLC2025/(CLC_[A-Z0-9]+)$", href)
        if match:
            code = match.group(1).replace("CLC_", "")
            regions.append({"code": code, "label": link["text"], "index_url": BASE + "html-tpls/2025NLE/CLC2025/" + match.group(1) + ".html"})

    for region in regions:
        region_links = links(region["index_url"])
        added = 0
        for link in region_links:
            href = link.get("href", "")
            if href.lower().endswith(".pdf") and "2025NLE/COC_2025NLE/CLC2025_" in href:
                sources.append({"region": region["code"], "label": link["text"], "url": pdf_url(href), "index_url": region["index_url"]})
                added += 1
        region["pdf_count"] = added

    seen = set()
    duplicates = []
    for source in sources:
        if source["url"] in seen:
            duplicates.append(source["url"])
        seen.add(source["url"])

    catalog = {
        "schema_version": "comelec-2025-clc-source-catalog-1",
        "source_index": INDEX,
        "regions": regions,
        "pdf_count": len(sources),
        "duplicate_urls": duplicates,
        "sources": sources,
    }
    json.dump(catalog, sys.stdout, ensure_ascii=False, indent=2)
    print()


if __name__ == "__main__":
    main()
