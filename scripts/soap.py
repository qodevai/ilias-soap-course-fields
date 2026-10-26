"""Shared zeep client for the scratch ILIAS on :8081."""
from zeep import Client
from zeep.transports import Transport
import requests

# ILIAS builds URLs from the Host header and fetches its own WSDL there, so the
# client must claim localhost:8080 (valid inside the container) while connecting to :8081.
BASE = "http://localhost:8080"
CLIENT_ID = "scratch"


class _Rewrite(requests.adapters.HTTPAdapter):
    """Connect to the published port 8081, keep Host: localhost:8080."""

    def send(self, request, **kw):
        if "localhost:8080" in request.url:
            request.url = request.url.replace("localhost:8080", "localhost:8081")
            request.headers["Host"] = "localhost:8080"
        return super().send(request, **kw)


def client() -> Client:
    s = requests.Session()
    s.mount("http://", _Rewrite())
    return Client(f"{BASE}/soap/server.php?client_id={CLIENT_ID}&wsdl", transport=Transport(session=s))


def login(c: Client, user: str, password: str) -> str:
    return c.service.login(client=CLIENT_ID, username=user, password=password)
