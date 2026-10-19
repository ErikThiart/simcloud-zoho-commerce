#!/usr/bin/env python3
"""Run source test harnesses against the exact extracted widget/connector ZIP runtime."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import zipfile

root = Path(__file__).resolve().parents[1]
subprocess.run(['python3', 'scripts/build.py'], cwd=root, check=True)
version = json.loads((root / 'package.json').read_text())['version']
with tempfile.TemporaryDirectory(prefix='simcloud-zoho-package-') as directory:
    runtime = Path(directory)
    for kind in ['widget', 'connector']:
        path = root / 'dist' / f'simcloud-zoho-{kind}-{version}.zip'
        with zipfile.ZipFile(path) as archive:
            assert all(not name.startswith('/') and '..' not in Path(name).parts for name in archive.namelist())
            assert all('config.php' != Path(name).name and not name.endswith('.sqlite') for name in archive.namelist())
            assert 'app/demo.json' not in archive.namelist()
            archive.extractall(runtime)
    manifest = json.loads((runtime / 'plugin-manifest.json').read_text())
    assert manifest['service'] == 'COMMERCE'
    assert manifest['usedConnections'] == []
    assert all(value == '' for value in json.loads((root / 'config/zoho-bindings.example.json').read_text()).values())
    assert 'https://static.zohocdn.com/zohofinance/v2.0/zf_sdk.js' in (runtime / 'app/widget.html').read_text()
    assert {w['location'] for w in manifest['modules']['widgets']} == {'application.allpages.sidebar', 'salesorder.details.sidebar'}
    with zipfile.ZipFile(root / 'dist' / f'simcloud-zoho-settings-{version}.zip') as archive:
        settings = json.loads(archive.read('plugin-manifest.json'))
        assert settings['modules']['widgets'][0]['location'] == 'plugin.globalfields'
        assert settings['usedConnections'] == manifest['usedConnections']
        assert b'https://static.zohocdn.com/zohofinance/v2.0/zf_sdk.js' in archive.read('app/widget.html')
    env = os.environ | {'SC_TEST_RUNTIME': str(runtime)}
    subprocess.run(['npm', 'test'], cwd=root, env=env, check=True)
    print('Exact widget/connector ZIP runtime passed source test harnesses; settings manifest checked.')
