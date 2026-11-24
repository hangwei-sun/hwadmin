from contextlib import closing
import importlib.util
import io
import json
from pathlib import Path
import shutil
import sqlite3
import tarfile
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('backup_restore', Path(__file__).parents[1] / 'backup_restore.py')
backup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(backup)


class BackupRestoreTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.server = self.root / 'server'
        for name in ['config', 'runtime', 'public/uploads', 'runtime/storage/private']:
            (self.server / name).mkdir(parents=True, exist_ok=True)
        self.old_server = backup.SERVER
        backup.SERVER = self.server
        self.database = self.server / 'runtime/test.sqlite'
        with closing(sqlite3.connect(self.database)) as connection:
            connection.execute('CREATE TABLE proof (name TEXT)')
            connection.execute("INSERT INTO proof VALUES ('restored')")
            connection.commit()
        (self.server / '.env').write_text(f'APP_DEBUG=false\nDB_DRIVER=sqlite\nDB_NAME="{self.database.as_posix()}"\nDB_PASS="quoted pass=a b"\n')
        (self.server / 'config/install.lock').write_text('installed')
        (self.server / 'public/uploads/proof.txt').write_text('public')
        (self.server / 'runtime/storage/private/proof.txt').write_text('private')

    def tearDown(self):
        backup.SERVER = self.old_server
        self.temp.cleanup()

    def create_backup(self):
        backup.backup(self.root / 'backup')
        return next((self.root / 'backup').glob('*.tar.gz'))

    @unittest.skipUnless(shutil.which('php'), 'PHP required')
    def test_roundtrip_database_media_config_and_install_lock(self):
        source = self.create_backup()
        self.database.unlink()
        (self.server / 'public/uploads/proof.txt').unlink()
        (self.server / 'runtime/storage/private/proof.txt').unlink()
        (self.server / 'config/install.lock').unlink()
        backup.restore(source)
        with closing(sqlite3.connect(self.database)) as connection:
            self.assertEqual(connection.execute('SELECT name FROM proof').fetchone()[0], 'restored')
        self.assertEqual((self.server / 'public/uploads/proof.txt').read_text(), 'public')
        self.assertEqual((self.server / 'runtime/storage/private/proof.txt').read_text(), 'private')
        self.assertEqual((self.server / 'config/install.lock').read_text(), 'installed')
        self.assertEqual(backup.read_env(self.server / '.env')['DB_PASS'], 'quoted pass=a b')

    @unittest.skipUnless(shutil.which('php'), 'PHP required')
    def test_missing_internal_manifest_rejected_before_target_changes(self):
        source = self.create_backup()
        modified = self.root / 'bad.tar.gz'
        with tarfile.open(source) as original, tarfile.open(modified, 'w:gz') as dest:
            for member in original.getmembers():
                if member.name != 'manifest.json':
                    dest.addfile(member, original.extractfile(member))
        modified.with_name(modified.name + '.sha256').write_text(backup.digest(modified) + '  bad.tar.gz')
        before = (self.server / '.env').read_bytes()
        with self.assertRaisesRegex(ValueError, '内部清单缺失'):
            backup.restore(modified)
        self.assertEqual(before, (self.server / '.env').read_bytes())

    @unittest.skipUnless(shutil.which('php'), 'PHP required')
    def test_missing_external_checksum_rejected(self):
        source = self.create_backup()
        source.with_name(source.name + '.sha256').unlink()
        with self.assertRaisesRegex(ValueError, 'SHA-256'):
            backup.restore(source)

    def test_tar_path_traversal_and_symlink_rejected(self):
        for name, link in [('../escape', False), ('uploads/escape', True)]:
            with self.subTest(name=name):
                stream = io.BytesIO()
                with tarfile.open(fileobj=stream, mode='w') as archive:
                    member = tarfile.TarInfo(name)
                    if link:
                        member.type = tarfile.SYMTYPE
                        member.linkname = '/etc'
                    archive.addfile(member)
                stream.seek(0)
                with tarfile.open(fileobj=stream) as archive, self.assertRaises(ValueError):
                    backup.safe_members(archive)

    @unittest.skipUnless(shutil.which('php'), 'PHP required')
    def test_snapshot_failure_never_falls_back_to_file_copy(self):
        self.database.write_text('broken sqlite database')
        with self.assertRaises(Exception):
            self.create_backup()
        self.assertFalse(list((self.root / 'backup').glob('*.tar.gz')))

    def mark_live_database(self):
        with closing(sqlite3.connect(self.database)) as connection:
            connection.execute("UPDATE proof SET name = 'live'")
            connection.commit()
        return self.database.read_bytes()

    @unittest.skipUnless(shutil.which('php'), 'PHP required')
    def test_restore_rejects_sqlite_outside_runtime_without_writes(self):
        source = self.create_backup()
        before = self.mark_live_database()
        outsider = self.root / 'outside.sqlite'
        outsider.write_bytes(b'outside sentinel')
        env_before = (self.server / '.env').read_bytes()
        for db_name in (str(outsider), '../outside.sqlite'):
            with self.subTest(db_name=db_name), patch.object(backup, 'read_env', return_value={'DB_DRIVER': 'sqlite', 'DB_NAME': db_name}):
                with self.assertRaises(ValueError):
                    backup.restore(source)
                self.assertEqual(outsider.read_bytes(), b'outside sentinel')
                self.assertEqual(self.database.read_bytes(), before)
                self.assertEqual((self.server / '.env').read_bytes(), env_before)

    @unittest.skipUnless(shutil.which('php'), 'PHP required')
    def test_media_type_conflict_rejected_before_database_restore(self):
        source = self.create_backup()
        before = self.mark_live_database()
        conflict = self.server / 'public/uploads/proof.txt'
        conflict.unlink()
        conflict.mkdir()
        env_before = (self.server / '.env').read_bytes()
        with self.assertRaises(ValueError):
            backup.restore(source)
        self.assertEqual(self.database.read_bytes(), before)
        self.assertEqual((self.server / '.env').read_bytes(), env_before)
        self.assertTrue(conflict.is_dir())

    @unittest.skipUnless(shutil.which('php'), 'PHP required')
    def test_config_parent_file_rejected_before_database_restore(self):
        source = self.create_backup()
        before = self.mark_live_database()
        env_before = (self.server / '.env').read_bytes()
        shutil.rmtree(self.server / 'config')
        (self.server / 'config').write_text('do not overwrite')
        with self.assertRaises(ValueError):
            backup.restore(source)
        self.assertEqual(self.database.read_bytes(), before)
        self.assertEqual((self.server / '.env').read_bytes(), env_before)
        self.assertEqual((self.server / 'config').read_text(), 'do not overwrite')

    @unittest.skipUnless(shutil.which('php'), 'PHP required')
    def test_config_symlink_rejected_before_database_restore(self):
        source = self.create_backup()
        before = self.mark_live_database()
        outsider = self.root / 'external-config'
        outsider.write_text('external sentinel')
        env_file = self.server / '.env'
        env_file.unlink()
        try:
            env_file.symlink_to(outsider)
        except OSError as error:
            self.skipTest(f'symlink unavailable: {error}')
        with self.assertRaises(ValueError):
            backup.restore(source)
        self.assertEqual(self.database.read_bytes(), before)
        self.assertEqual(outsider.read_text(), 'external sentinel')
        self.assertTrue(env_file.is_symlink())

    def test_mysql_uses_consistent_transaction_and_no_password_argument(self):
        with patch.object(shutil, 'which', return_value='/usr/bin/mysqldump'):
            args, env = backup.sql_command({'DB_DRIVER': 'mysql', 'DB_PASS': 'test secret'}, True)
        self.assertIn('--single-transaction', args)
        self.assertNotIn('test secret', ' '.join(args))
        self.assertEqual(env['MYSQL_PWD'], 'test secret')


if __name__ == '__main__':
    unittest.main()
