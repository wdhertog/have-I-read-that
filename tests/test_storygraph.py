import io

from app import storygraph

CSV = """﻿Title,Authors,Contributors,ISBN/UID,Format,Read Status,Date Added,Last Date Read,Dates Read,Read Count,Moods,Pace,Character- or Plot-Driven?,Strong Character Development?,Loveable Characters?,Diverse Characters?,Flawed Characters?,Star Rating,Review,Content Warnings,Content Warning Description,Tags,Owned?
De avonden,Gerard Reve,,9789023417069,paperback,read,2023/01/02,2024/03/09,"2023/01/05-2023/01/20, 2024/03/01-2024/03/09",2,reflective,slow,Character,Yes,No,No,Yes,4.25,"Mooi, ""grijs"" boek.",,,klassiekers,Yes
Project Hail Mary,Andy Weir,,B08FHBV4ZX,ebook,currently-reading,2024/05/01,,2024/05/02-,0,,,,,,,,,,,,,No
Dune,Frank Herbert,,9780441013593,,to-read,2022/12/01,,,0,,,,,,,,,,,,,No
Moby Dick,Herman Melville,,,audio,did-not-finish,2021/06/01,2021/07/01,,0,,,,,,,,,,,,,No
"""


def test_parse_rows():
    rows = list(storygraph.parse_rows(CSV.lstrip("﻿")))
    avonden, hail_mary, dune, moby = rows
    assert avonden["status"] == "read"
    assert avonden["rating"] == 4.25
    assert avonden["review"] == 'Mooi, "grijs" boek.'
    assert avonden["owned"] == 1
    assert avonden["reads"] == [("2023-01-05", "2023-01-20"), ("2024-03-01", "2024-03-09")]
    assert hail_mary["status"] == "reading"
    assert hail_mary["isbn"] is None  # StoryGraph UID / ASIN, not an ISBN
    assert hail_mary["reads"] == [("2024-05-02", None)]
    assert dune["status"] == "to-read" and dune["reads"] == []
    assert moby["status"] == "dnf" and moby["reads"] == [(None, "2021-07-01")]


def test_single_date_for_read_book_is_finish_date():
    assert storygraph.parse_dates_read("2023/01/20", "read") == [(None, "2023-01-20")]
    assert storygraph.parse_dates_read("2023/01/20", "reading") == [("2023-01-20", None)]


def test_import_is_idempotent_and_never_toots(client, toots):
    data = {"file": (io.BytesIO(CSV.encode()), "storygraph.csv")}
    resp = client.post("/import", data=data, content_type="multipart/form-data")
    assert "4 geïmporteerd" in resp.get_data(as_text=True)
    data = {"file": (io.BytesIO(CSV.encode()), "storygraph.csv")}
    resp = client.post("/import", data=data, content_type="multipart/form-data")
    assert "0 geïmporteerd, 4 overgeslagen" in resp.get_data(as_text=True)
    assert toots == []
    stats = client.get("/stats").get_data(as_text=True)
    assert "2024" in stats and "2023" in stats


def test_import_rejects_other_csv(client):
    data = {"file": (io.BytesIO(b"foo,bar\n1,2\n"), "x.csv")}
    resp = client.post("/import", data=data, content_type="multipart/form-data")
    assert "Import mislukt" in resp.get_data(as_text=True)
