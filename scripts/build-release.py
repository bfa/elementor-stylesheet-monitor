#!/usr/bin/env python3
"""Build only allowlisted plugin content; never package the surrounding workspace."""
from pathlib import Path
import hashlib
import re
import zipfile

root = Path(__file__).resolve().parents[1]
main = (root / 'elementor-stylesheet-monitor.php').read_text()
version = re.search(r'^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)$', main, re.M).group(1)
if f"const VERSION = '{version}';" not in main:
    raise SystemExit('Version header and constant disagree')
allowed_files = ['elementor-stylesheet-monitor.php', 'README.md', 'readme.txt', 'CHANGELOG.md', 'LICENSE', 'THIRD-PARTY.md', 'SECURITY.md']
files = [root / name for name in allowed_files]
for folder in ['includes', 'vendor']:
    files.extend(sorted(p for p in (root / folder).rglob('*') if p.is_file()))
for path in files:
    if path.is_symlink() or root not in path.resolve().parents:
        raise SystemExit('Refusing symlink/outside path')
    if path.suffix.lower() in ['.sql', '.log', '.zip'] or path.name.startswith('.env'):
        raise SystemExit('Unexpected artifact in package')
    text = path.read_bytes()
    if re.search(rb'(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----|(?:brent@bfa\.me|dev@xagency\.io|laketoxaway\.com|blueridgemountainclub\.com))', text, re.I):
        raise SystemExit('Private/site-specific content found: ' + str(path.relative_to(root)))
out = root / 'dist'
out.mkdir(exist_ok=True)
archive = out / f'elementor-stylesheet-monitor-{version}.zip'
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as package:
    for path in sorted(files):
        info = zipfile.ZipInfo('elementor-stylesheet-monitor/' + str(path.relative_to(root)), (2026, 1, 1, 0, 0, 0))
        info.external_attr = 0o100644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        package.writestr(info, path.read_bytes())
with zipfile.ZipFile(archive) as package:
    if package.testzip() is not None:
        raise SystemExit('ZIP integrity failure')
checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
(out / 'SHA256SUMS').write_text(f'{checksum}  {archive.name}\n')
print(f'{archive.name}: {len(files)} files; sha256 {checksum}')
