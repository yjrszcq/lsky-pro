#!/usr/bin/env python3
"""HTTP/browser acceptance against an isolated real production container."""
import argparse
import base64
import http.cookiejar
import json
import re
import secrets
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request


def docker(*args):
    return subprocess.check_output(["docker", *args], text=True, stderr=subprocess.STDOUT).strip()


def check(condition, message):
    if not condition:
        raise AssertionError(message)


def check_logs(name):
    logs = docker('logs', name)
    check(not re.search(r'PHP (Fatal error|Warning|Deprecated)', logs), 'PHP runtime errors in production log')


class Client:
    def __init__(self, base):
        self.base = base
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf = ""
        self.token = ""

    def request(self, path, data=None, method="GET", content_type=None):
        headers = {"Accept": "application/json" if data is not None or self.token else "text/html"}
        if self.csrf:
            headers["X-CSRF-TOKEN"] = self.csrf
        if self.token:
            headers["Authorization"] = "Bearer " + self.token
        if content_type:
            headers["Content-Type"] = content_type
        if isinstance(data, dict):
            data = json.dumps(data).encode()
            headers["Content-Type"] = "application/json"
        req = urllib.request.Request(self.base + path, data=data, headers=headers, method=method)
        try:
            response = self.opener.open(req, timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        body = response.read()
        match = re.search(rb'<meta name="csrf-token" content="([^"]+)"', body)
        if match:
            self.csrf = match[1].decode()
        return response.status, body, response.url

    def json(self, path, data=None, method="GET"):
        status, body, _ = self.request(path, data, method)
        check(status == 200, f"{method} {path}: HTTP {status}")
        result = json.loads(body)
        check(result.get("status") is True, f"{method} {path}: {result.get('message')}")
        return result["data"]

    def upload(self, png, path="/upload", strategy=None):
        boundary = "lsky" + secrets.token_hex(12)
        data = (f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="container.png"\r\n'
                'Content-Type: image/png\r\n\r\n').encode() + png + f'\r\n--{boundary}--\r\n'.encode()
        if strategy is not None:
            data = (f'--{boundary}\r\nContent-Disposition: form-data; name="strategy_id"\r\n\r\n{strategy}\r\n').encode() + data
        status, body, _ = self.request(path, data, "POST", f"multipart/form-data; boundary={boundary}")
        check(status == 200, f"Upload HTTP {status}")
        result = json.loads(body)
        check(result.get("status") is True, f"Upload: {result.get('message')}")
        return result["data"]


def browser_acceptance(base, email, password, png):
    from playwright.sync_api import sync_playwright
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True, args=["--no-sandbox"])
        context = browser.new_context(permissions=["clipboard-read", "clipboard-write"])
        page = context.new_page()
        errors = []
        page.on("pageerror", lambda error: errors.append(str(error)))
        page.goto(base + "/login", wait_until="domcontentloaded")
        page.locator('#email').fill(email)
        page.locator('input[name="password"]').fill(password)
        page.locator('form button').click()
        page.wait_for_url("**/dashboard", wait_until="domcontentloaded")
        for path in ["/upload", "/images", "/settings", "/gallery", "/api", "/admin/console", "/admin/users", "/admin/images", "/admin/groups", "/admin/strategies", "/admin/settings"]:
            response = page.goto(base + path, wait_until="domcontentloaded")
            check(response.status == 200, "Browser page " + path)
            page.wait_for_timeout(200)
        page.goto(base + "/upload", wait_until="domcontentloaded")
        page.locator('#picker').set_input_files({"name": "browser.png", "mimeType": "image/png", "buffer": png})
        page.locator('[data-operate="upload"]').wait_for(state="visible")
        with page.expect_response(lambda response: response.url == base + '/upload' and response.request.method == 'POST') as result:
            page.locator('[data-operate="upload"]').click()
        check(result.value.json().get('status') is True, "Browser upload failed")
        page.locator('[uploaded="true"]').wait_for()
        encoded = base64.b64encode(png).decode()
        for index, mode in enumerate(['drop', 'paste'], start=1):
            page.evaluate("""({encoded, mode}) => {
                const bytes = Uint8Array.from(atob(encoded), char => char.charCodeAt(0));
                const transfer = new DataTransfer();
                transfer.items.add(new File([bytes], mode + '.png', {type: 'image/png'}));
                if (mode === 'drop') {
                    // Synthetic files have no filesystem entries; use the standard FileList path.
                    Object.defineProperty(transfer, 'items', {value: undefined});
                    document.querySelector('#picker-dnd').dispatchEvent(new DragEvent('drop', {bubbles: true, cancelable: true, dataTransfer: transfer}));
                } else {
                    document.dispatchEvent(new ClipboardEvent('paste', {bubbles: true, clipboardData: transfer}));
                }
            }""", {'encoded': encoded, 'mode': mode})
            control = page.locator('[data-operate="upload"]').nth(index)
            control.wait_for(state='visible')
            with page.expect_response(lambda response: response.url == base + '/upload' and response.request.method == 'POST') as result:
                control.click()
            check(result.value.json().get('status') is True, 'Browser ' + mode + ' upload')
        page.locator('#picker').set_input_files([
            {'name': 'batch-one.png', 'mimeType': 'image/png', 'buffer': png},
            {'name': 'batch-two.png', 'mimeType': 'image/png', 'buffer': png},
        ])
        page.locator('[data-operate="upload"]').nth(4).wait_for(state='visible')
        page.locator('#upload-all').click()
        page.wait_for_function("document.querySelectorAll('[uploaded]').length === 5")
        page.locator('[data-tab-name="markdown"]').click()
        check(page.locator('[data-tab="markdown"] p').count() == 5, 'Generated Markdown links')
        page.locator('#links-container').hover()
        page.locator('#copy-all').click()
        clipboard = page.evaluate('navigator.clipboard.readText()')
        check(clipboard.count('![') == 5, 'Copy all Markdown links')
        check(not errors, "Browser JavaScript errors: " + "; ".join(errors))
        print("PASS Chromium login, 11 pages, file picker, drag/drop, clipboard paste, batch upload and copy links", flush=True)
        browser.close()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--image', default='lsky-pro:latest')
    parser.add_argument('--browser', action='store_true')
    parser.add_argument('--web-port', type=int, default=8089)
    args = parser.parse_args()
    name = 'lsky-acceptance-' + secrets.token_hex(4)
    volume = name + '-data'
    password = secrets.token_urlsafe(18)
    email = 'admin@example.test'
    port = None
    base = None
    docker('volume', 'create', volume)

    def start():
        nonlocal port, base
        mapping = f'127.0.0.1:{port or ""}:{args.web_port}'
        docker('run', '-d', '--name', name, '-p', mapping, '-e', 'WEB_PORT=' + str(args.web_port), '-v', volume + ':/var/www/html/storage', args.image)
        if port is None:
            port = int(docker('port', name, str(args.web_port) + '/tcp').rsplit(':', 1)[1])
        base = f'http://127.0.0.1:{port}'
        client = Client(base)
        for _ in range(120):
            try:
                if client.request('/')[0] == 200:
                    return client
            except (urllib.error.URLError, ConnectionError):
                pass
            time.sleep(0.5)
        raise AssertionError('Production container did not become ready')

    try:
        client = start()
        check(docker('exec', name, 'php', '-r', 'echo PHP_VERSION;') == '8.5.12', 'PHP version')
        _, _, url = client.request('/')
        check(url.endswith('/install'), 'New deployment must open installer')
        client.json('/install', {'connection': 'sqlite', 'database': '', 'account': {'email': email, 'password': password}}, 'POST')
        check(client.request('/install')[0] == 404, 'Installer must be locked after setup')
        check(docker('exec', name, 'test', '-f', '/var/www/html/storage/database.sqlite') == '', 'SQLite persistence path')
        print('PASS exact PHP version, HTTP installer, persistent SQLite and installation lock', flush=True)

        png = base64.b64decode(docker('exec', name, 'php', '-r', '$i=new Imagick();$i->newImage(640,480,"red");$i->setImageFormat("png");echo base64_encode($i->getImagesBlob());'))
        client.request('/')
        guest = client.upload(png)
        check(client.request(urllib.parse.urlsplit(guest['links']['url']).path)[0] == 200, 'Guest original')
        check(client.request(urllib.parse.urlsplit(guest['links']['thumbnail_url']).path)[0] == 200, 'Guest thumbnail')
        print('PASS guest multipart upload, original image and thumbnail HTTP responses', flush=True)

        member = Client(base)
        member.request('/register')
        status, _, _ = member.request('/register', {'name': 'ContainerUser', 'email': 'member@example.test', 'password': password, 'password_confirmation': password}, 'POST')
        check(status == 200, 'Registration')
        check(member.request('/dashboard')[0] == 200, 'Registered session')
        check(member.request('/admin/console')[0] == 403, 'Admin authorization')
        print('PASS registration, authenticated session and admin access restriction', flush=True)

        client.request('/login')
        client.request('/login', {'email': email, 'password': password}, 'POST')
        client.request('/dashboard')
        for path in ['/dashboard', '/upload', '/images', '/settings', '/gallery', '/api', '/admin/console', '/admin/users', '/admin/images', '/admin/groups', '/admin/strategies', '/admin/settings']:
            check(client.request(path)[0] == 200, 'Page ' + path)
        for path in ['/css/app.css', '/css/common.css', '/js/app.js', '/js/blueimp-file-upload/jquery.fileupload.js']:
            check(client.request(path)[0] == 200, 'Built asset ' + path)
        check(client.request('/.env')[0] in [403, 404], 'Configuration must not be public')
        print('PASS admin login, application/admin routes, built assets and document root isolation', flush=True)

        client.json('/admin/strategies/create', {'key': 1, 'name': 'Persistent-local', 'configs': {'url': base + '/persisted-local'}, 'groups': [1]}, 'POST')
        strategy_id = next(item['id'] for item in Client(base).json('/api/v1/strategies')['strategies'] if item['name'] == 'Persistent-local')
        uploaded = client.upload(png, strategy=strategy_id)
        image_id = uploaded['id']
        client.json('/user/images/rename', {'id': image_id, 'name': 'container-renamed.png'}, 'PUT')
        client.json('/user/images/permission', {'ids': [image_id], 'permission': 'public'}, 'PUT')
        client.json('/user/albums', {'name': 'Container-album'}, 'POST')
        album = client.json('/user/albums')['albums']['data'][0]
        client.json('/user/images/movement', {'selected': [image_id], 'id': album['id']}, 'PUT')
        client.json('/user/albums/' + str(album['id']), {'name': 'Renamed-album'}, 'PUT')
        client.json('/user/albums/' + str(album['id']), method='DELETE')
        print('PASS image rename/visibility and album create/move/rename/delete', flush=True)

        api = Client(base)
        api.token = api.json('/api/v1/tokens', {'email': email, 'password': password}, 'POST')['token']
        check(api.json('/api/v1/profile')['email'] == email, 'Token profile')
        api_upload = api.upload(png, '/api/v1/upload')
        api.json('/api/v1/images')
        api.json('/api/v1/albums')
        api.json('/api/v1/strategies')
        api.json('/api/v1/images/' + api_upload['key'], method='DELETE')
        print('PASS bearer token issue/authentication, API upload/list/delete and metadata endpoints', flush=True)

        if args.browser:
            browser_acceptance(base, email, password, png)

        # Exercise one-off CLI containers with the existing persistent state.
        migration = docker('run', '--rm', '-v', volume + ':/var/www/html/storage', args.image, 'php', 'artisan', 'migrate', '--force')
        check('Nothing to migrate' in migration, 'One-off migrations must use persisted database')
        key_hash = docker('exec', name, 'php', '-r', 'echo hash_file("sha256", "storage/.env");')
        check_logs(name)
        docker('rm', '-f', name)
        client = start()
        check(not client.request('/')[2].endswith('/install'), 'Container recreation must retain installation')
        check(docker('exec', name, 'php', '-r', 'echo hash_file("sha256", "storage/.env");') == key_hash, 'Persisted encryption key/config')
        check(client.request(urllib.parse.urlsplit(uploaded['links']['url']).path)[0] == 200, 'Persisted original image')
        check(client.request(urllib.parse.urlsplit(uploaded['links']['thumbnail_url']).path)[0] == 200, 'Persisted thumbnail')
        check(api.json('/api/v1/profile')['email'] == email, 'Persisted API token/database')
        api.json('/api/v1/tokens', method='DELETE')
        check(api.request('/api/v1/profile')[0] == 401, 'Token revocation')
        print('PASS one-off migration, container recreation, config/key/database/image/thumbnail/token persistence and revocation', flush=True)

        for _ in range(120):
            health = docker('inspect', '--format', '{{.State.Health.Status}}', name)
            if health == 'healthy':
                break
            time.sleep(0.5)
        check(health == 'healthy', 'Production Docker health check')
        check_logs(name)
        print('PASS Docker health check and clean production PHP logs', flush=True)
        print('Production container acceptance passed.', flush=True)
    except Exception:
        subprocess.run(['docker', 'logs', '--tail', '60', name], check=False)
        raise
    finally:
        subprocess.run(['docker', 'rm', '-f', name], check=False, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        subprocess.run(['docker', 'volume', 'rm', volume], check=False, stdout=subprocess.DEVNULL)


if __name__ == '__main__':
    main()
