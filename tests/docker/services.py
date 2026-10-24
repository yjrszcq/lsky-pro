"""Disposable protocol fixtures; never use these credentials outside tests."""
import json
import os
import signal
import subprocess
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer
from aiosmtpd.controller import Controller
from pyftpdlib.authorizers import DummyAuthorizer
from pyftpdlib.handlers import FTPHandler
from pyftpdlib.servers import FTPServer

messages = []


class MailHandler:
    async def handle_DATA(self, server, session, envelope):
        messages.append(envelope.content.decode(errors="replace"))
        return "250 Message accepted"


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        body = json.dumps({"messages": messages}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        self.rfile.read(int(self.headers.get("Content-Length", "0")))
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(b'{"porn":0.99,"hentai":0,"sexy":0}')


authorizer = DummyAuthorizer()
authorizer.add_user("integration", "container-test-password", "/data/ftp", perm="elradfmwMT")
FTPHandler.authorizer = authorizer
ftp = FTPServer(("0.0.0.0", 21), FTPHandler)
threading.Thread(target=ftp.serve_forever, daemon=True).start()
Controller(MailHandler(), hostname="0.0.0.0", port=8025).start()
children = [
    subprocess.Popen(["/usr/sbin/sshd", "-D", "-e"]),
    subprocess.Popen(["moto_server", "-H", "0.0.0.0", "-p", "5000"]),
    subprocess.Popen(["wsgidav", "--host=0.0.0.0", "--port=8080", "--root=/data/webdav", "--auth=anonymous"]),
]


def shutdown(signum, frame):
    for child in children:
        child.terminate()
    os._exit(0)


signal.signal(signal.SIGTERM, shutdown)
HTTPServer(("0.0.0.0", 8070), Handler).serve_forever()
