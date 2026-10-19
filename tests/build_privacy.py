"""Exercise public defaults and explicit local metadata boundaries; no network."""
import json
import secrets
from pathlib import Path
import subprocess
import tempfile
import zipfile

root = Path(__file__).resolve().parents[1]
version = json.loads((root / 'package.json').read_text())['version']
checks = 0
fixture = root / 'app/js/publication-fixture.js'
try:
    subprocess.run(['python3', 'scripts/build.py'], cwd=root, check=True, stdout=subprocess.DEVNULL)
    with zipfile.ZipFile(root / 'dist' / f'simcloud-zoho-widget-{version}.zip') as archive:
        assert json.loads(archive.read('plugin-manifest.json'))['usedConnections'] == []
        assert b'"health": ""' in archive.read('app/js/config.js')
        checks += 2
    with tempfile.TemporaryDirectory(prefix='simcloud-build-metadata-') as directory:
        private = Path(directory)
        bindings = private / 'bindings.json'
        bindings.write_text(json.dumps({key: 'ac_example_' + key for key in ['health', 'catalogue', 'import', 'fixed', 'order']}))
        connection = private / 'connection.json'
        metadata = {'connectionLinkName': 'exampleconnector', 'connectionName': 'Example connector', 'serviceName': 'exampleservice',
                    'userAccess': True, 'isUserDefinedService': True, 'sharedBy': '12345'}
        connection.write_text(json.dumps(metadata))
        command = ['python3', 'scripts/build.py', '--bindings', str(bindings), '--connection', str(connection)]
        subprocess.run(command, cwd=root, check=True, stdout=subprocess.DEVNULL)
        with zipfile.ZipFile(root / 'dist' / f'simcloud-zoho-widget-{version}.zip') as archive:
            assert json.loads(archive.read('plugin-manifest.json'))['usedConnections'] == [metadata]
            assert b'ac_example_health' in archive.read('app/js/config.js')
            assert not any('bindings.json' in name or 'connection.json' in name for name in archive.namelist())
            checks += 3
        blocked = subprocess.run(['python3', 'scripts/public_check.py'], cwd=root, capture_output=True)
        assert blocked.returncode != 0 and b'account-bound' in blocked.stderr
        checks += 1
        metadata['client_secret'] = 'synthetic-not-a-real-secret'
        connection.write_text(json.dumps(metadata))
        rejected = subprocess.run(command, cwd=root, capture_output=True)
        assert rejected.returncode != 0 and b'credentials are forbidden' in rejected.stderr
        checks += 1
    subprocess.run(['python3', 'scripts/build.py'], cwd=root, check=True, stdout=subprocess.DEVNULL)
    secret = secrets.token_hex(32)
    fixture.write_text('const example = ' + json.dumps({'simcloud_token': secret}) + ';')
    blocked = subprocess.run(['python3', 'scripts/public_check.py'], cwd=root, capture_output=True)
    assert blocked.returncode != 0 and b'named credential literal' in blocked.stderr and secret.encode() not in blocked.stderr
    checks += 1
    with zipfile.ZipFile(root / 'dist' / f'simcloud-zoho-widget-{version}.zip') as archive:
        assert 'app/js/publication-fixture.js' not in archive.namelist()
        checks += 1
finally:
    fixture.unlink(missing_ok=True)
    subprocess.run(['python3', 'scripts/build.py'], cwd=root, check=True, stdout=subprocess.DEVNULL)
print(f'Passed {checks} package privacy checks; final packages are unbound.')
