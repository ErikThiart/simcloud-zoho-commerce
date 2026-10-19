#!/usr/bin/env python3
"""Reject private deployment artifacts and account-bound data before publication."""
import hashlib
import json
from pathlib import Path
import re
import subprocess
import zipfile
from urllib.parse import urlsplit

root = Path(__file__).resolve().parents[1]
errors = set()
allowed_roots = {'.github', '.githooks', 'app', 'config', 'docs', 'public', 'scripts', 'server', 'tests'}
ignored = {'.git', 'node_modules', 'dist', '__pycache__'}
patterns = {
    'private filesystem path': rb'/(?:Users|home)/[^/\s]+/',
    'account-specific namespace': rb'(?:ac|cf)__com_[a-z0-9]+_',
    'account-specific portal URL': rb'(?:commerce\.zoho\.[a-z.]+/app/|demo-)[0-9]{7,}',
    'account identifier literal': rb'(?i)(?:sharedBy|organization_id|extension_id)["\x27]?\s*[:=]\s*["\x27][0-9]{8,30}["\x27]',
    'provider token literal': rb'1000\.[A-Za-z0-9]{20,}\.[A-Za-z0-9]{20,}',
    'GitHub token literal': rb'(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,})',
    'private key': rb'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',
    'bearer credential literal': rb'Bearer[ \t]+[A-Za-z0-9_.-]{32,}',
    'named credential literal': rb'(?i)(?:simcloud_token|client_secret|refresh_token|bridge_key|encryption_key)["\x27]?\s*(?::|=>|=)\s*["\x27][A-Za-z0-9_./+=-]{32,4096}["\x27]',
}

def inspect(name, data):
    for label, pattern in patterns.items():
        if re.search(pattern, data):
            errors.add(f'{name}: {label}')
    if Path(name).suffix.lower() in {'.png', '.jpg', '.jpeg', '.webp'}:
        return
    text = data.decode('utf-8', errors='replace')
    for url in re.findall(r'https?://[^\s<>"\x27\x60]+', text):
        parsed = urlsplit(url.rstrip(').,;'))
        if parsed.hostname == 'simcloud.co.za' and parsed.path not in {'', '/'} and not parsed.path.startswith(('/api/', '/img/')):
            errors.add(f'{name}: non-public website route')

def inspect_path(name):
    path = Path(name)
    if len(path.parts) > 1 and path.parts[0] not in allowed_roots:
        errors.add(f'{name}: unsupported source directory')
    if (path.name in {'config.php', 'host.config.php', 'error_log', '.env', 'zoho-bindings.json'}
        or path.name.endswith(('.local.json', '.sqlite', '.sqlite-wal', '.sqlite-shm', '.log'))
        or path.name.startswith('.env.')
        or name in {'public/connect.php', 'server/hosted.php', 'server/host-worker.php'}):
        errors.add(f'{name}: private runtime artifact')

files = [p for p in root.rglob('*') if p.is_file() and not any(part in ignored for part in p.relative_to(root).parts)]
for path in files:
    name = str(path.relative_to(root))
    inspect_path(name)
    inspect(name, path.read_bytes())
images = json.loads((root / 'config/public-images.json').read_text())
for path in files:
    if path.suffix.lower() == '.png':
        name = str(path.relative_to(root))
        if hashlib.sha256(path.read_bytes()).hexdigest() != images.get(name):
            errors.add(f'{name}: image needs public-content review')

# Keep private data out of every reachable commit as well as the current tree.
if (root / '.git').exists():
    result = subprocess.run(['git', 'rev-list', '--objects', '--all'], cwd=root, text=True, capture_output=True, check=True)
    for item in result.stdout.splitlines():
        oid, _, name = item.partition(' ')
        kind = subprocess.check_output(['git', 'cat-file', '-t', oid], cwd=root, text=True).strip()
        if kind == 'blob':
            inspect_path(name)
            inspect('history:' + name, subprocess.check_output(['git', 'cat-file', 'blob', oid], cwd=root))

packages = 0
for path in (root / 'dist').glob('*.zip'):
    packages += 1
    with zipfile.ZipFile(path) as archive:
        for name in archive.namelist():
            inspect_path(name)
            data = archive.read(name)
            inspect(path.name + ':' + name, data)
            if name == 'plugin-manifest.json' and json.loads(data).get('usedConnections') != []:
                errors.add(f'{path.name}: account-bound connection metadata')
            if name == 'app/js/config.js':
                match = re.search(rb'Object\.freeze\((\{.*\})\)', data)
                if not match or any(json.loads(match.group(1)).values()):
                    errors.add(f'{path.name}: account-bound API names')
if errors:
    raise SystemExit('\n'.join(sorted(errors)))
print(f'Public-source checks passed for {len(files)} files, reachable history and {packages} runtime ZIPs.')
