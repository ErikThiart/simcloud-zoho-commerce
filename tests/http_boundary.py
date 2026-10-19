"""Exercise the real PHP front controller without making any external API requests."""
import json
import base64
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

root = Path(os.environ.get('SC_TEST_RUNTIME', Path(__file__).resolve().parents[1]))
with tempfile.TemporaryDirectory(prefix='simcloud-zoho-http-') as private:
    config = Path(private) / 'config.php'
    config.write_text("<?php return [" +
        "'organization_id'=>'12345','bridge_key'=>'" + 'x' * 40 + "','simcloud_token'=>'synthetic-not-a-real-token'," +
        "'database'=>" + json.dumps(str(Path(private) / 'ledger.sqlite')) + "," +
        "'encryption_key'=>" + json.dumps(base64.b64encode(b'k' * 32).decode()) + "," +
        "'zoho_region'=>'com','recipient_field'=>'cf_simcloud_recipient','meter_field'=>'cf_simcloud_meter','live_enabled'=>false];")
    config.chmod(0o600)
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    env = os.environ | {'SIMCLOUD_ZOHO_CONFIG': str(config)}
    server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(root / 'public')], env=env,
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.1):
                    break
            except OSError:
                time.sleep(.03)
        def request(action, method='GET', payload=None, authorized=False, org='12345'):
            headers = {'Content-Type': 'application/json'}
            if authorized:
                headers |= {'Authorization': 'Bearer ' + 'x' * 40, 'X-SIMcloud-Organization': org}
            call = urllib.request.Request(f'http://127.0.0.1:{port}/bridge.php?action={action}',
                data=payload, method=method, headers=headers)
            try:
                response = urllib.request.urlopen(call, timeout=5)
            except urllib.error.HTTPError as error:
                response = error
            body = response.read().decode()
            assert 'synthetic-not-a-real-token' not in body and private not in body
            assert response.headers['Cache-Control'] == 'no-store, private'
            return response.status, json.loads(body)
        assert request('health')[0] == 401
        assert request('order', authorized=True, org='99999')[0] == 401
        assert request('unknown', authorized=True)[0] == 405
        assert request('enqueue', authorized=True)[0] == 405
        assert request('import', 'POST', b'not json', True)[0] == 400
        assert request('import', 'POST', b'x' * 16385, True)[0] == 413
        assert request('enqueue', 'POST', b'{"order_id":"9001"}', True)[0] == 409
        print('Passed 7 HTTP boundary checks: authentication, organization, method, JSON, size and disabled fulfilment.')
    finally:
        server.terminate()
        server.wait(timeout=5)
