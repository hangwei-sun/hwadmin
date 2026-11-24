import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('build_release', Path(__file__).parents[1] / 'build-release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class ReleaseSQLiteArtifactsTest(unittest.TestCase):
    def test_sqlite_sidecars_outside_runtime_are_excluded(self):
        for name in ('data.sqlite-wal', 'data.sqlite-shm', 'data.sqlite-journal',
                     'data.sqlite3-wal', 'data.db-journal'):
            with self.subTest(name=name):
                self.assertTrue(release.should_exclude_deploy('database/' + name, name))

    def test_ordinary_database_migration_is_preserved(self):
        self.assertFalse(release.should_exclude_deploy('database/migrations/schema.sql', 'schema.sql'))

    def test_legacy_uploaded_storage_is_not_a_release_payload(self):
        self.assertTrue(release.should_exclude_deploy('public/storage/avatar/image.png', 'image.png'))

    def test_interrupted_installer_credentials_never_enter_release(self):
        for name in ('.env', '.env-install-abcdef', '.env-write-abcdef', '.env.pre_restore_20261028'):
            with self.subTest(name=name):
                self.assertTrue(release.should_exclude_deploy(name, name))
        self.assertFalse(release.should_exclude_deploy('.example.env', '.example.env'))


if __name__ == '__main__':
    unittest.main()
