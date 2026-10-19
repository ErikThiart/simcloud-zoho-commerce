#!/usr/bin/env python3
"""Build reproducible runtime ZIPs from an explicit list; never read private config."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import zipfile

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--bindings', type=Path, help='Local JSON with five Zoho-generated API configuration names')
parser.add_argument('--connection', type=Path, help='Local JSON with Zoho-generated usedConnections metadata; no credentials')
args = parser.parse_args()
version = json.loads((root / 'package.json').read_text())['version']
bindings = {key: '' for key in ['health', 'catalogue', 'import', 'fixed', 'order']}
connections = []
if args.bindings:
    supplied = json.loads(args.bindings.read_text())
    if set(supplied) != set(bindings) or not all(isinstance(v, str) and re.fullmatch(r'ac_[A-Za-z0-9_]+', v) for v in supplied.values()):
        raise SystemExit('Bindings must contain exactly five ac_* API names. Credentials are forbidden.')
    bindings = supplied
if args.connection:
    supplied = json.loads(args.connection.read_text())
    fields = {'connectionLinkName', 'connectionName', 'serviceName', 'userAccess', 'isUserDefinedService', 'sharedBy'}
    if not isinstance(supplied, dict) or set(supplied) != fields:
        raise SystemExit('Connection JSON must contain only Zoho-generated metadata; credentials are forbidden.')
    if (not all(isinstance(supplied[k], str) and re.fullmatch(r'[A-Za-z][A-Za-z0-9_]{0,99}', supplied[k]) for k in ['connectionLinkName', 'serviceName'])
        or not isinstance(supplied['connectionName'], str) or not re.fullmatch(r'[^\r\n]{1,100}', supplied['connectionName'])
        or not isinstance(supplied['sharedBy'], str) or not re.fullmatch(r'[1-9][0-9]{0,29}', supplied['sharedBy'])
        or type(supplied['userAccess']) is not bool or type(supplied['isUserDefinedService']) is not bool):
        raise SystemExit('Connection metadata is invalid.')
    connections = [supplied]

files = json.loads((root / 'config/package-files.json').read_text())
dist = root / 'dist'
dist.mkdir(exist_ok=True)
checksums = []
for kind in ['widget', 'settings', 'connector']:
    names = files['connector' if kind == 'connector' else 'widget']
    name = f'simcloud-zoho-{kind}-{version}.zip'
    path = dist / name
    with zipfile.ZipFile(path, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for relative in sorted(names):
            if Path(relative).is_absolute() or '..' in Path(relative).parts:
                raise SystemExit('Invalid package file path.')
            data = (root / relative).read_bytes()
            if relative == 'app/js/config.js' and kind != 'connector':
                data = ('"use strict";\nwindow.SIMCLOUD_CONFIG = Object.freeze(' + json.dumps(bindings, sort_keys=True) + ');\n').encode()
            if relative == 'plugin-manifest.json':
                manifest = json.loads(data)
                manifest['usedConnections'] = connections
                if kind == 'settings':
                    manifest['modules']['widgets'] = [{'location': 'plugin.globalfields', 'name': 'SIMcloud setup',
                        'url': '/app/widget.html', 'logo': '/app/img/logo.png'}]
                data = json.dumps(manifest, indent=2).encode() + b'\n'
            info = zipfile.ZipInfo(relative, date_time=(2026, 1, 1, 0, 0, 0))
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, data)
    checksum = hashlib.sha256(path.read_bytes()).hexdigest()
    checksums.append(f'{checksum}  {name}')
    print(f'{name}: {len(names)} files; {checksum}')
(dist / 'SHA256SUMS').write_text('\n'.join(checksums) + '\n')
