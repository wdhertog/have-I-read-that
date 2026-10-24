from app import mastodon


def test_login_required(app):
    c = app.test_client()
    assert c.get("/").status_code == 302
    assert "Onjuist" in c.post("/login", data={"password": "fout"}, follow_redirects=True).get_data(as_text=True)


def test_reading_flow_posts_to_mastodon(client, toots):
    resp = client.post("/add", data={"title": "De avonden", "author": "Gerard Reve", "status": "to-read", "toot": "on"})
    assert resp.status_code == 302
    book_url = resp.headers["Location"]
    assert toots == []  # nothing to say about a to-read book

    client.post(book_url + "/start", data={"started": "2024-01-01", "toot": "on"})
    assert "Begonnen aan: De avonden – Gerard Reve" in toots[-1]

    client.post(book_url + "/finish", data={"finished": "2024-01-10", "rating": "4,5",
                                            "review": "Prachtig.", "toot": "on"})
    assert len(toots) == 2
    assert "Uitgelezen: De avonden" in toots[-1] and "★★★★½" in toots[-1] and "Prachtig." in toots[-1]

    page = client.get(book_url).get_data(as_text=True)
    assert "2024-01-01" in page and "2024-01-10" in page and "bekijk toot" in page


def test_finish_without_toot(client, toots):
    resp = client.post("/add", data={"title": "Stil", "status": "reading"})
    client.post(resp.headers["Location"] + "/finish", data={})
    assert toots == []


def test_compose_truncates_long_review():
    book = {"title": "T", "author": "A", "rating": 3, "review": "x" * 1000}
    text = mastodon.compose(mastodon.DEFAULT_TEMPLATES["finished"], book, 500)
    assert len(text) <= 500 and "…" in text and text.endswith("#bookstodon")


def test_compose_without_review_has_no_blank_gap():
    text = mastodon.compose(mastodon.DEFAULT_TEMPLATES["finished"], {"title": "T", "author": "A"})
    assert "\n\n\n" not in text


def test_stars():
    assert mastodon.stars(4.25) == "★★★★½"
    assert mastodon.stars(3) == "★★★☆☆"
    assert mastodon.stars(None) == ""
