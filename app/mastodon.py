"""Minimal Mastodon client: compose and send a status."""

import json
import re
import urllib.error
import urllib.parse
import urllib.request

DEFAULT_TEMPLATES = {
    "finished": "📖 Uitgelezen: {title} – {author}\n{stars}\n\n{review}\n\n#boeken #bookstodon",
    "started": "📚 Begonnen aan: {title} – {author}\n\n#boeken #bookstodon",
}


class MastodonError(Exception):
    pass


def stars(rating):
    if not rating:
        return ""
    halves = int(rating * 2 + 0.5)
    full, half = divmod(halves, 2)
    return "★" * full + ("½" if half else "") + "☆" * (5 - full - half)


def compose(template, book, max_chars=500):
    """Fill the template for a book, shortening the review if the toot gets too long."""
    review = (book.get("review") or "").strip()
    fields = {
        "title": book.get("title") or "",
        "author": book.get("author") or "onbekend",
        "stars": stars(book.get("rating")),
        "rating": f"{book['rating']:g}/5" if book.get("rating") else "",
        "isbn": book.get("isbn") or "",
    }

    def render(review_text):
        text = template.replace("\\n", "\n").format_map(_Default(fields, review=review_text))
        text = "\n".join(line.rstrip() for line in text.splitlines())
        return re.sub(r"\n{3,}", "\n\n", text).strip()

    text = render(review)
    if len(text) > max_chars and review:
        keep = len(review) - (len(text) - max_chars) - 1
        text = render(review[:keep].rstrip() + "…" if keep > 0 else "")
    return text[:max_chars]


class _Default(dict):
    def __missing__(self, key):
        return ""


class Client:
    def __init__(self, instance, token, visibility="public", timeout=15):
        instance = (instance or "").strip().rstrip("/")
        if instance and not instance.startswith(("http://", "https://")):
            instance = "https://" + instance
        self.instance = instance
        self.token = (token or "").strip()
        self.visibility = visibility
        self.timeout = timeout

    @property
    def configured(self):
        return bool(self.instance and self.token)

    def _request(self, method, path, data=None, headers=None):
        if not self.configured:
            raise MastodonError("Mastodon is niet geconfigureerd.")
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        req = urllib.request.Request(self.instance + path, data=body, method=method)
        req.add_header("Authorization", f"Bearer {self.token}")
        req.add_header("User-Agent", "have-i-read-that")
        for key, value in (headers or {}).items():
            req.add_header(key, value)
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                return json.load(resp)
        except urllib.error.HTTPError as exc:
            try:
                detail = json.load(exc).get("error", "")
            except Exception:
                detail = ""
            raise MastodonError(f"Mastodon gaf HTTP {exc.code}: {detail or exc.reason}") from exc
        except (urllib.error.URLError, TimeoutError) as exc:
            raise MastodonError(f"Kon {self.instance} niet bereiken: {exc}") from exc

    def verify(self):
        """Return the account the token belongs to."""
        return self._request("GET", "/api/v1/accounts/verify_credentials")

    def post(self, text, idempotency_key=None):
        """Post a status and return its public URL."""
        headers = {"Idempotency-Key": idempotency_key} if idempotency_key else None
        status = self._request(
            "POST", "/api/v1/statuses",
            {"status": text, "visibility": self.visibility}, headers,
        )
        return status.get("url") or status.get("uri")
