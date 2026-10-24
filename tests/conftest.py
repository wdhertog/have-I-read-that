import pytest

from app import create_app


@pytest.fixture
def app(tmp_path, monkeypatch):
    monkeypatch.setenv("DATA_DIR", str(tmp_path))
    return create_app({
        "TESTING": True,
        "APP_PASSWORD": "geheim",
        "MASTODON_INSTANCE": "example.social",
        "MASTODON_ACCESS_TOKEN": "token",
    })


@pytest.fixture
def client(app):
    c = app.test_client()
    c.post("/login", data={"password": "geheim"})
    return c


@pytest.fixture
def toots(monkeypatch):
    sent = []

    def fake_post(self, text, idempotency_key=None):
        sent.append(text)
        return f"https://example.social/@me/{len(sent)}"

    monkeypatch.setattr("app.mastodon.Client.post", fake_post)
    return sent
