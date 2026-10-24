"""have-i-read-that: a small self-hosted reading log that toots to Mastodon."""

import os
import secrets
from pathlib import Path

from flask import Flask

from . import db, mastodon, openlibrary


def _secret_key(data_dir):
    if os.environ.get("SECRET_KEY"):
        return os.environ["SECRET_KEY"]
    key_file = data_dir / "secret_key"
    if not key_file.exists():
        key_file.write_text(secrets.token_hex(32))
        key_file.chmod(0o600)
    return key_file.read_text().strip()


def create_app(config=None):
    app = Flask(__name__)
    data_dir = Path(os.environ.get("DATA_DIR", "data"))
    data_dir.mkdir(parents=True, exist_ok=True)

    app.config.update(
        DATABASE=str(data_dir / "books.sqlite3"),
        APP_PASSWORD=os.environ.get("APP_PASSWORD", ""),
        MASTODON_INSTANCE=os.environ.get("MASTODON_INSTANCE", ""),
        MASTODON_ACCESS_TOKEN=os.environ.get("MASTODON_ACCESS_TOKEN", ""),
        MASTODON_VISIBILITY=os.environ.get("MASTODON_VISIBILITY", "public"),
        MASTODON_MAX_CHARS=int(os.environ.get("MASTODON_MAX_CHARS", "500")),
        MASTODON_POST_BY_DEFAULT=os.environ.get("MASTODON_POST_BY_DEFAULT", "true").lower() in ("1", "true", "yes"),
        MASTODON_TEMPLATE_FINISHED=os.environ.get("MASTODON_TEMPLATE_FINISHED", mastodon.DEFAULT_TEMPLATES["finished"]),
        MASTODON_TEMPLATE_STARTED=os.environ.get("MASTODON_TEMPLATE_STARTED", mastodon.DEFAULT_TEMPLATES["started"]),
        SESSION_COOKIE_SAMESITE="Lax",
        SESSION_COOKIE_HTTPONLY=True,
        SESSION_COOKIE_SECURE=os.environ.get("SESSION_COOKIE_SECURE", "false").lower() in ("1", "true", "yes"),
        MAX_CONTENT_LENGTH=20 * 1024 * 1024,
    )
    if config:
        app.config.update(config)
    if not app.config.get("SECRET_KEY"):
        app.config["SECRET_KEY"] = _secret_key(data_dir)

    db.init_db(app.config["DATABASE"])
    app.teardown_appcontext(db.close_db)

    from .views import bp
    app.register_blueprint(bp)

    app.jinja_env.filters["stars"] = mastodon.stars
    app.jinja_env.globals["cover_url"] = openlibrary.cover_url
    return app
