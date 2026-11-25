"""Run the Stage 2 forms against a fresh SQLite demo database on loopback."""
import http.cookiejar
import os
from pathlib import Path
import re
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BIN", "php")


def browser():
    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
    )


def run(url, database):
    def request(session, path, fields=None, expected=200):
        body = urllib.parse.urlencode(fields).encode() if fields is not None else None
        try:
            response = session.open(f"{url}/{path}", body, timeout=5)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            page = response.read().decode()
            assert response.code == expected, (path, response.code, page)
            return page

    def login(email):
        session = browser()
        result = request(session, "login_result.php", {
            "email": email, "password": "zimugao",
        })
        assert "Login successful!" in result
        return session

    buyer = login("buyer@qq.com")
    seller = login("seller@qq.com")
    rival = login("rival@qq.com")
    login("both@qq.com")
    guest = browser()

    assert "Bootstrap" in request(guest, "css/bootstrap.min.css")
    request(guest, "css/custom.css")
    assert "Bootstrap" in request(guest, "js/bootstrap.bundle.min.js")
    listing = "listing.php?item_id=1"
    with sqlite3.connect(database) as connection:
        assert connection.execute("SELECT COUNT(*) FROM Bid WHERE auctionID=1").fetchone() == (2,)
        assert 'name="csrf"' not in request(seller, listing)
        initial = request(rival, listing + "&refresh=1")
        assert "£51.00" in initial and "<!doctype" not in initial
        token = re.search(r'name="csrf" value="([0-9a-f]{64})"', request(buyer, listing))[1]
        bid = {"auction_id": 1, "amount": "66.00", "csrf": token}
        request(guest, "place_bid.php", bid, expected=403)
        request(buyer, "place_bid.php", bid | {"csrf": ""}, expected=403)
        request(buyer, "place_bid.php", {"auction_id": 1, "amount[]": "66", "csrf": token}, expected=400)
        for bad_amount in ("hello", "-1.00", "66.001", "1e2"):
            invalid = request(buyer, "place_bid.php", bid | {"amount": bad_amount})
            assert "Enter a positive price with at most two decimal places." in invalid
        invalid = request(buyer, "place_bid.php", bid | {"amount": "51.99"})
        assert "Bid at least £52.00" in invalid
        assert connection.execute("SELECT COUNT(*) FROM Bid WHERE auctionID=1").fetchone() == (2,)
        assert "Your bid was placed." in request(buyer, "place_bid.php", bid)
        changed = request(rival, listing + "&refresh=1")
        assert "£66.00" in changed and "Minimum £67.00" in changed
        assert "£66.00" in request(buyer, "mybids.php")
        assert "£66.00" in request(seller, "mylistings.php")
        assert "£66.00" in request(guest, "browse.php?order_by=pricehigh")

        connection.execute("UPDATE Auction SET endsAt = datetime('now', '-1 minute') WHERE auctionID=1")
        connection.commit()
        assert "Sold to" in request(guest, listing + "&refresh=1")
        assert "You won!" in request(buyer, "notifications.php")
        assert "Your auction City bicycle sold for £66.00" in request(seller, "notifications.php")
        assert "You won!" not in request(rival, "notifications.php")
        expired = request(buyer, "place_bid.php", bid | {"amount": "67.00"})
        assert "This auction has ended." in expired
        assert connection.execute("SELECT COUNT(*) FROM Bid WHERE auctionID=1").fetchone() == (3,)
        assert connection.execute("SELECT COUNT(*) FROM SaleOrder WHERE auctionID=1").fetchone() == (1,)
    print("PASS Stage 2 HTTP: login, valid and invalid POSTs, live fragment, views, settlement and notices.")


def main():
    with tempfile.TemporaryDirectory(prefix="stage2-auction-test-") as temporary:
        database = Path(temporary) / "demo.sqlite"
        env = dict(os.environ, DB_DSN=f"sqlite:{database}")
        subprocess.run([PHP, "bin/setup.php", "--demo"], cwd=ROOT, env=env, check=True)
        with socket.socket() as probe:
            probe.bind(("127.0.0.1", 0))
            port = probe.getsockname()[1]
        address = f"127.0.0.1:{port}"
        with (Path(temporary) / "server.log").open("w+") as log:
            server = subprocess.Popen(
                [PHP, "-S", address, "-t", "stage2"], cwd=ROOT, env=env,
                stdout=log, stderr=log,
            )
            try:
                for _ in range(50):
                    try:
                        with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                            break
                    except OSError:
                        if server.poll() is not None:
                            break
                        time.sleep(0.1)
                else:
                    raise RuntimeError("PHP didn't start.")
                if server.poll() is not None:
                    log.seek(0)
                    raise RuntimeError(log.read())
                run(f"http://{address}", database)
            finally:
                if server.poll() is None:
                    server.terminate()
                server.wait(timeout=5)


if __name__ == "__main__":
    main()
