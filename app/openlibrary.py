"""Book lookup via the Open Library search API (no API key needed)."""

import json
import urllib.parse
import urllib.request


def search(query, limit=10, timeout=10):
    url = "https://openlibrary.org/search.json?" + urllib.parse.urlencode({
        "q": query,
        "limit": limit,
        "fields": "title,author_name,isbn,number_of_pages_median,first_publish_year",
    })
    req = urllib.request.Request(url, headers={"User-Agent": "have-i-read-that"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        docs = json.load(resp).get("docs", [])
    results = []
    for doc in docs:
        isbns = doc.get("isbn") or []
        isbn = next((i for i in isbns if len(i) == 13), isbns[0] if isbns else "")
        results.append({
            "title": doc.get("title", ""),
            "author": ", ".join(doc.get("author_name", [])[:3]),
            "isbn": isbn,
            "pages": doc.get("number_of_pages_median") or "",
            "year": doc.get("first_publish_year") or "",
        })
    return results


def cover_url(isbn, size="M"):
    if not isbn:
        return None
    return f"https://covers.openlibrary.org/b/isbn/{isbn}-{size}.jpg?default=false"
