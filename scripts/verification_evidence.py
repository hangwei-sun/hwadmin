#!/usr/bin/env python3
"""Bind verification results to the source and release files actually checked."""
import hashlib
import json
import os
from pathlib import Path
import sys


def file_sha256(path):
    digest = hashlib.sha256()
    with Path(path).open('rb') as stream:
        for chunk in iter(lambda: stream.read(65536), b''):
            digest.update(chunk)
    return digest.hexdigest()


def source_fingerprint(root):
    root = Path(root).resolve()
    paths = set()
    # Source and contracts; exclude data, dependencies and generated build output.
    for name in ('hw', 'hw.ps1', 'hw.cmd', 'hw.project.json', 'docker-compose.yml',
                 'docker-compose.dev.yml', '.env.docker.example'):
        if (root / name).is_file():
            paths.add(name)
    excluded = {'.git', '.hw-state', 'node_modules', 'vendor', 'runtime', 'dist',
                'dist-desktop', 'unpackage', 'target', 'gen', '__pycache__', '.cache',
                '.vite', 'uploads', 'storage'}
    for name in ('server', 'admin-ui', 'scripts', 'templates', 'docker'):
        for directory, dirs, files in os.walk(root / name, followlinks=False):
            dirs[:] = sorted(d for d in dirs if d not in excluded
                             and not (Path(directory) / d).is_symlink()
                             and not (Path(directory) / d).relative_to(root).as_posix().endswith('public/admin'))
            for filename in sorted(files):
                path = Path(directory) / filename
                if path.is_symlink() or filename.startswith(('.env', 'install.lock', 'install.token')):
                    continue
                if path.suffix in {'.pyc', '.log', '.tsbuildinfo', '.sqlite', '.db'}:
                    continue
                paths.add(path.relative_to(root).as_posix())
    digest = hashlib.sha256()
    for name in sorted(paths):
        digest.update(name.encode('utf-8') + b'\0')
        digest.update(file_sha256(root / name).encode('ascii') + b'\n')
    return digest.hexdigest()


def attach_evidence(root, report_path):
    root, report_path = Path(root).resolve(), Path(report_path).resolve()
    report = json.loads(report_path.read_text(encoding='utf-8'))
    version_path = root / 'server/version.json'
    if not version_path.is_file():
        version_path = root / 'version.json'
    version = json.loads(version_path.read_text(encoding='utf-8'))['version']
    artifacts = []
    for path in sorted((root / 'release').glob(f'hwadmin-*-v{version}.zip')):
        artifacts.append({'filename': path.name, 'sha256': file_sha256(path)})
    report['evidence'] = {'schema_version': 1, 'source_sha256': source_fingerprint(root),
                          'artifacts': artifacts}
    temporary = report_path.with_suffix('.tmp')
    temporary.write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    temporary.replace(report_path)


if __name__ == '__main__':
    attach_evidence(sys.argv[1], sys.argv[2])
