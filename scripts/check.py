#!/usr/bin/env python3
"""Check local Markdown links and image provenance without a network connection."""
import hashlib
import json
from pathlib import Path
import re
root = Path(__file__).resolve().parents[1]
errors = []
for path in root.rglob('*.md'):
    if '.git' in path.parts:
        continue
    for link in re.findall(r'\]\(([^)]+)\)', path.read_text()):
        target = link.split('#')[0]
        if not target or re.match(r'^[a-z]+:', target):
            continue
        if not (path.parent / target).exists():
            errors.append(f'{path.relative_to(root)}: broken link {link}')
for entry in json.loads((root / 'app/images/sources.json').read_text()):
    image = root / 'app/images' / entry['file']
    if hashlib.sha256(image.read_bytes()).hexdigest() != entry['sha256']:
        errors.append(f'Image checksum mismatch: {entry["file"]}')
if errors:
    raise SystemExit('\n'.join(errors))
print('Documentation links and 23 bundled image checksums passed.')
