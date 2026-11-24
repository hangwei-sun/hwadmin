import argparse
import contextlib
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import sqlite3
import stat
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
import zipfile

SCRIPTS = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(SCRIPTS))
spec = importlib.util.spec_from_file_location('hw_deploy', SCRIPTS / 'hw-deploy.py')
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)
from verification_evidence import attach_evidence


class DeployIntegrityTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='hwadmin-deploy-integrity-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)

    def artifact(self, members=None, extra=None):
        path = self.root / 'release/hwadmin-release-v1.4.2.zip'
        path.parent.mkdir(exist_ok=True)
        members = members or {'think': b'fixture', 'version.json': b'{"version":"1.4.2"}'}
        manifest = {'artifact_type': 'deploy', 'framework_version': '1.4.2',
                    'required_files': ['think'],
                    'files': {n: hashlib.sha256(v).hexdigest() for n, v in members.items()}}
        with zipfile.ZipFile(path, 'w') as archive:
            for name, content in members.items():
                archive.writestr(name, content)
            archive.writestr('artifact-manifest.json', json.dumps(manifest))
            if extra:
                archive.writestr(*extra)
        self.sign(path)
        return path

    def sign(self, path):
        Path(str(path) + '.sha256').write_text(hashlib.sha256(path.read_bytes()).hexdigest() + ' *' + path.name)

    def test_valid_archive_passes_but_external_checksum_is_required(self):
        path = self.artifact()
        self.assertTrue(deploy.verify_artifact_strictly(path)[0])
        Path(str(path) + '.sha256').unlink()
        self.assertFalse(deploy.verify_artifact_strictly(path)[0])

    def test_unlisted_payload_is_rejected(self):
        path = self.artifact(extra=('unlisted.php', b'not in manifest'))
        self.assertFalse(deploy.verify_artifact_strictly(path)[0])

    @unittest.skipUnless(os.name != 'nt' and shutil.which('bash'), 'requires a POSIX bash')
    def test_verify_failure_still_outputs_parseable_json_without_evidence_helper(self):
        entry = self.root / 'hw'
        shutil.copy2(SCRIPTS.parent / 'hw', entry)
        result = subprocess.run(['bash', str(entry), 'verify', '--json'], capture_output=True, text=True)
        self.assertNotEqual(0, result.returncode)
        self.assertEqual('fail', json.loads(result.stdout)['status'])

    def bootstrap(self, clients, failure=0, flags=(), consume_input=False):
        shutil.copy2(SCRIPTS.parent / 'hw', self.root / 'hw')
        (self.root / 'server/vendor').mkdir(parents=True)
        (self.root / 'server/think').touch()
        (self.root / 'server/vendor/autoload.php').touch()
        (self.root / 'scripts').mkdir()
        (self.root / 'scripts/wsl-dev.sh').write_text('echo ready > "$BOOTSTRAP_MARKER"\n')
        for directory in ('admin-ui', 'admin-ui/mobile'):
            (self.root / directory).mkdir(parents=True, exist_ok=True)
            (self.root / directory / 'package.json').write_text('{}')
        (self.root / 'hw.project.json').write_text(json.dumps({'clients': clients}))
        env = os.environ.copy()
        env.update(HW_ENTRY=str(self.root / 'hw'), PNPM_TRACE=str(self.root / 'pnpm.log'),
                   PNPM_EXIT=str(failure), PNPM_READ=str(int(consume_input)),
                   BOOTSTRAP_MARKER=str(self.root / 'initialized'))
        script = '''php() { :; }; node() { :; }; pnpm() {
          printf '%s\\n' "$*" >> "$PNPM_TRACE";
          if [ "$PNPM_READ" = 1 ]; then cat >/dev/null; fi
          return "$PNPM_EXIT";
        }; export -f php node pnpm; exec bash "$HW_ENTRY" bootstrap "$@"'''
        return subprocess.run(['bash', '-c', script, 'bootstrap-test', *flags], env=env,
                              capture_output=True, text=True)

    @unittest.skipUnless(os.name != 'nt' and shutil.which('bash'), 'requires a POSIX bash')
    def test_bootstrap_installs_only_selected_locked_clients_before_initializing(self):
        result = self.bootstrap(['mobile-h5'])
        self.assertEqual(0, result.returncode, result.stderr)
        trace = (self.root / 'pnpm.log').read_text()
        self.assertIn('--dir admin-ui/mobile install --frozen-lockfile --prefer-offline', trace)
        self.assertEqual(1, len(trace.splitlines()))
        self.assertTrue((self.root / 'initialized').exists())

    @unittest.skipUnless(os.name != 'nt' and shutil.which('bash'), 'requires a POSIX bash')
    def test_bootstrap_dependency_failure_prevents_database_initialization(self):
        result = self.bootstrap(['admin-pc'], failure=23)
        self.assertEqual(23, result.returncode)
        self.assertFalse((self.root / 'initialized').exists())

    @unittest.skipUnless(os.name != 'nt' and shutil.which('bash'), 'requires a POSIX bash')
    def test_package_manager_stdin_cannot_skip_the_next_client(self):
        result = self.bootstrap(['admin-pc', 'mobile-h5'], consume_input=True)
        self.assertEqual(0, result.returncode, result.stderr)
        trace = (self.root / 'pnpm.log').read_text().splitlines()
        self.assertEqual(2, len(trace))
        self.assertIn('--dir admin-ui/mobile install', trace[1])
        self.assertTrue((self.root / 'initialized').exists())

    @unittest.skipUnless(os.name != 'nt' and shutil.which('bash'), 'requires a POSIX bash')
    def test_backend_only_does_not_claim_to_install_frontend_dependencies(self):
        result = self.bootstrap(['admin-pc', 'mobile-h5'], flags=('--backend-only',))
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertFalse((self.root / 'pnpm.log').exists())
        self.assertTrue((self.root / 'initialized').exists())

    def test_unsafe_member_paths_are_rejected_before_any_write(self):
        for name in ('../outside.php', '../target-sibling/payload.php', '/absolute.php',
                     'a/../../escape.php', 'C:/escape.php', 'a\\escape.php', 'a/./payload.php'):
            with self.subTest(name=name):
                path = self.artifact({'think': b'fixture', name: b'unsafe'})
                self.assertFalse(deploy.verify_artifact_strictly(path)[0])

    def test_duplicate_and_symlink_members_are_rejected(self):
        for symlink in (False, True):
            path = self.artifact()
            info = zipfile.ZipInfo('link' if symlink else 'THINK')
            if symlink:
                info.external_attr = (stat.S_IFLNK | 0o777) << 16
            with zipfile.ZipFile(path, 'a') as archive:
                archive.writestr(info, b'../outside')
            self.sign(path)
            self.assertFalse(deploy.verify_artifact_strictly(path)[0])

    def test_rollback_rejects_traversal_before_deleting_existing_code(self):
        target = self.root / 'target'
        target.mkdir()
        sentinel = target / 'preserve.php'
        sentinel.write_text('existing code')
        backup = self.root / 'backup'
        (backup / 'code').mkdir(parents=True)
        archive = backup / 'code/snapshot.zip'
        with zipfile.ZipFile(archive, 'w') as stream:
            stream.writestr('../target-sibling/payload.php', 'outside')
        (backup / 'backup-manifest.json').write_text(json.dumps({
            'target_dir': str(target), 'target_server_dir': str(target),
            'checksums': {'code/snapshot.zip': hashlib.sha256(archive.read_bytes()).hexdigest()},
            'data_backups': {},
        }))
        args = argparse.Namespace(backup_dir=str(backup), target_dir=str(target), mode='full', json=True)
        with contextlib.redirect_stdout(io.StringIO()):
            self.assertNotEqual(0, deploy.cmd_rollback(args, self.root))
        self.assertEqual('existing code', sentinel.read_text())
        self.assertFalse((self.root / 'target-sibling').exists())

    def test_sqlite_snapshot_includes_committed_wal_and_restores_safely(self):
        live = self.root / 'live.sqlite'
        with contextlib.closing(sqlite3.connect(live)) as db:
            db.execute('PRAGMA journal_mode=WAL')
            db.execute('CREATE TABLE test(value TEXT)')
            db.execute("INSERT INTO test VALUES ('before')")
            db.commit()
            snapshot = self.root / 'snapshot.sqlite'
            deploy.backup_sqlite(live, snapshot)
            db.execute("UPDATE test SET value='after'")
            db.commit()
        deploy.backup_sqlite(snapshot, live)
        with contextlib.closing(sqlite3.connect(live)) as db:
            self.assertEqual('before', db.execute('SELECT value FROM test').fetchone()[0])

    def test_external_adapter_cannot_claim_a_local_probe_is_production_success(self):
        args = argparse.Namespace(target='docker', dry_run=False, json=True, artifact=None)
        with contextlib.redirect_stdout(io.StringIO()) as output:
            code = deploy.cmd_apply(args, self.root)
        self.assertNotEqual(0, code)
        self.assertEqual('adapter', json.loads(output.getvalue())['step'])
        self.assertFalse((self.root / 'runtime').exists())

    def test_apply_preserves_an_existing_recovery_point(self):
        archive = self.artifact()
        backup = self.root / 'existing-backup'
        backup.mkdir()
        sentinel = backup / 'snapshot.txt'
        sentinel.write_text('previous recovery point')
        target = self.root / 'target'
        args = argparse.Namespace(target='local', dry_run=False, json=True,
                                  artifact=str(archive), target_dir=str(target), backup_dir=str(backup))
        with contextlib.redirect_stdout(io.StringIO()):
            self.assertNotEqual(0, deploy.cmd_apply(args, self.root))
        self.assertEqual('previous recovery point', sentinel.read_text())
        self.assertFalse(target.exists())

    def report(self):
        with contextlib.redirect_stdout(io.StringIO()) as output:
            deploy.cmd_report(argparse.Namespace(format='json', json=True), self.root)
        return json.loads(output.getvalue())

    def test_delivery_report_rejects_stale_source_and_changed_artifact(self):
        self.artifact()
        (self.root / 'server').mkdir()
        (self.root / 'server/version.json').write_text('{"version":"1.4.2"}')
        source = self.root / 'server/source.php'
        source.write_text('original source')
        report_file = self.root / '.hw-state/verify-report.json'
        report_file.parent.mkdir()
        ids = ['lint', 'doctor', 'phpunit', 'smoke', 'pc_types', 'mobile_types', 'package']
        report_file.write_text(json.dumps({'status': 'pass', 'summary': {'all_passed': True},
                                          'checks': [{'id': n, 'pass': True} for n in ids]}))
        self.assertFalse(self.report()['ready_for_delivery'])
        attach_evidence(self.root, report_file)
        self.assertTrue(self.report()['ready_for_delivery'])
        source.write_text('changed source')
        self.assertEqual('stale_verification', self.report()['status'])
        attach_evidence(self.root, report_file)
        self.assertTrue(self.report()['ready_for_delivery'])
        archive = next((self.root / 'release').glob('*.zip'))
        archive.write_bytes(b'changed archive')
        self.assertFalse(self.report()['ready_for_delivery'])


if __name__ == '__main__':
    unittest.main()
