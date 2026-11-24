import importlib.util
import json
from pathlib import Path
import sqlite3
import tempfile
import unittest
from types import SimpleNamespace

spec = importlib.util.spec_from_file_location('starter', Path(__file__).resolve().parents[1] / 'start-project.py')
starter = importlib.util.module_from_spec(spec)
spec.loader.exec_module(starter)


class StartProjectTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / 'hw.project.json').write_text(json.dumps({'app_name': 'existing', 'entities': {'custom': {}}, 'brand': {}}))
        self.calls = []

    def run_command(self, command, **kwargs):
        self.calls.append(command)
        return SimpleNamespace(returncode=0)

    def _init_website_db(self, db_path: Path) -> None:
        db_path.parent.mkdir(parents=True, exist_ok=True)
        conn = sqlite3.connect(str(db_path))
        with conn:
            conn.executescript(
                """
                CREATE TABLE hw_config (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, value TEXT, update_time INTEGER);
                INSERT INTO hw_config (name, value, update_time) VALUES ('home_mode', 'portal', 0);
                CREATE TABLE hw_site_category (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, slug TEXT, sort INTEGER,
                    status INTEGER, create_time INTEGER, update_time INTEGER
                );
                CREATE TABLE hw_site_article (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER, type TEXT, title TEXT,
                    slug TEXT, summary TEXT, content TEXT, cover_image TEXT, price REAL, views INTEGER,
                    seo_title TEXT, seo_keywords TEXT, seo_description TEXT, canonical_url TEXT,
                    status INTEGER, create_time INTEGER, update_time INTEGER
                );
                CREATE TABLE hw_site_inquiry (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, mobile TEXT, email TEXT,
                    company TEXT, target_id INTEGER, content TEXT, remark TEXT, status INTEGER,
                    create_time INTEGER, update_time INTEGER
                );
                """
            )
        conn.close()

    def _init_admin_db(self, db_path: Path) -> None:
        db_path.parent.mkdir(parents=True, exist_ok=True)
        conn = sqlite3.connect(str(db_path))
        with conn:
            conn.executescript(
                """
                CREATE TABLE hw_enterprise_project (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, project_no TEXT, title TEXT,
                    admin_id INTEGER, created_by INTEGER, priority INTEGER, budget REAL,
                    status INTEGER, create_time INTEGER, update_time INTEGER
                );
                CREATE TABLE hw_enterprise_task (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, title TEXT,
                    assignee TEXT, deadline TEXT, created_by INTEGER, status INTEGER,
                    create_time INTEGER, update_time INTEGER
                );
                """
            )
        conn.close()

    def _init_miniapp_db(self, db_path: Path) -> None:
        db_path.parent.mkdir(parents=True, exist_ok=True)
        conn = sqlite3.connect(str(db_path))
        with conn:
            conn.executescript(
                """
                CREATE TABLE hw_booking_service (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, cover TEXT,
                    duration INTEGER, price REAL, status INTEGER, create_time INTEGER, update_time INTEGER
                );
                CREATE TABLE hw_booking_slot (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, service_id INTEGER, date TEXT,
                    start_time TEXT, end_time TEXT, capacity INTEGER, booked_count INTEGER,
                    status INTEGER, create_time INTEGER, update_time INTEGER
                );
                """
            )
        conn.close()

    def test_website_selects_only_pc_and_applies_template_without_force(self):
        self.assertEqual(0, starter.prepare(self.root, 'website', '网站', self.run_command))
        data = json.loads((self.root / 'hw.project.json').read_text(encoding='utf-8'))
        self.assertEqual(['admin-pc'], data['clients'])
        self.assertIn('custom', data['entities'])
        self.assertIn('corporate-site', self.calls[1])
        self.assertNotIn('--force', self.calls[1])

    def test_resume_keeps_client_customization_and_does_not_reapply_template(self):
        starter.prepare(self.root, 'miniapp', runner=self.run_command)
        self.calls.clear()
        starter.prepare(self.root, runner=self.run_command)
        self.assertEqual(1, len(self.calls))
        self.assertIn('bootstrap', self.calls[0])

    def test_failure_does_not_mark_project_prepared(self):
        failed = lambda *args, **kwargs: SimpleNamespace(returncode=23)
        self.assertEqual(23, starter.prepare(self.root, 'admin', runner=failed))
        self.assertFalse((self.root / '.hw-state/start.json').exists())

    def test_website_syncs_brand_and_public_site_assets(self):
        ui_site = self.root / 'admin-ui/public/site'
        ui_site.mkdir(parents=True, exist_ok=True)
        (ui_site / 'index.html').write_text('<!doctype html><title>Demo</title>', encoding='utf-8')
        (self.root / 'server/public/admin').mkdir(parents=True, exist_ok=True)

        self.assertEqual(0, starter.prepare(self.root, 'website', '星辰设计', self.run_command))
        brand = json.loads((ui_site / 'brand.json').read_text(encoding='utf-8'))
        self.assertEqual('星辰设计', brand['name'])
        self.assertTrue(brand['tagline'])
        self.assertIn('星辰设计', brand['hero_title'])
        self.assertTrue(brand['hero_lead'])

        self.assertTrue((self.root / 'server/public/site/index.html').is_file())
        self.assertTrue((self.root / 'server/public/site/brand.json').is_file())
        self.assertTrue((self.root / 'server/public/admin/site/index.html').is_file())
        self.assertTrue((self.root / 'server/public/admin/site/brand.json').is_file())

    def test_seed_website_data_idempotent_and_non_destructive(self):
        db_path = self.root / 'server/runtime/dev.sqlite'
        self._init_website_db(db_path)

        first = starter.seed_initial_business_data(self.root, 'website', '星辰设计')
        self.assertEqual(3, first['hw_site_category'])
        self.assertEqual(3, first['hw_site_article'])
        self.assertEqual(1, first['hw_site_inquiry'])

        conn = sqlite3.connect(str(db_path))
        types = {row[0] for row in conn.execute('SELECT type FROM hw_site_article').fetchall()}
        self.assertEqual({'product', 'case', 'article'}, types)
        home_mode = conn.execute("SELECT value FROM hw_config WHERE name='home_mode'").fetchone()[0]
        self.assertEqual('cms', home_mode)

        # 反例：表非空时二次执行绝不覆盖或重复写入
        with conn:
            conn.execute("UPDATE hw_site_category SET name='自定义栏目' WHERE slug='services'")
        conn.close()

        second = starter.seed_initial_business_data(self.root, 'website', '另一个名字')
        self.assertEqual({}, second)

        conn = sqlite3.connect(str(db_path))
        self.assertEqual(3, conn.execute('SELECT COUNT(*) FROM hw_site_category').fetchone()[0])
        custom_name = conn.execute("SELECT name FROM hw_site_category WHERE slug='services'").fetchone()[0]
        self.assertEqual('自定义栏目', custom_name)
        conn.close()

    def test_seed_admin_and_miniapp_respects_existing_rows_and_release_layout(self):
        # 测试 admin 幂等填充与非空保护
        admin_db = self.root / 'server/runtime/dev.sqlite'
        self._init_admin_db(admin_db)
        seeded_admin = starter.seed_initial_business_data(self.root, 'admin', '协同中台')
        self.assertEqual(2, seeded_admin['hw_enterprise_project'])
        self.assertEqual(3, seeded_admin['hw_enterprise_task'])
        self.assertEqual({}, starter.seed_initial_business_data(self.root, 'admin', '协同中台'))

        # 反例：若已有自定义项目且无任务，不覆盖已有项目
        admin_db.unlink()
        self._init_admin_db(admin_db)
        conn = sqlite3.connect(str(admin_db))
        with conn:
            conn.execute(
                "INSERT INTO hw_enterprise_project (project_no, title, admin_id, created_by, priority, budget, status, create_time, update_time) VALUES ('CUSTOM01', '已有项目', 1, 1, 2, 100, 1, 1, 1)"
            )
        conn.close()
        seeded_partial = starter.seed_initial_business_data(self.root, 'admin', '协同中台')
        self.assertNotIn('hw_enterprise_project', seeded_partial)
        conn = sqlite3.connect(str(admin_db))
        self.assertEqual(1, conn.execute('SELECT COUNT(*) FROM hw_enterprise_project').fetchone()[0])
        conn.close()

        # 测试发布包布局 runtime/dev.sqlite 下的 miniapp 预约数据填充
        admin_db.unlink()
        release_db = self.root / 'runtime/dev.sqlite'
        self._init_miniapp_db(release_db)
        seeded_mini = starter.seed_initial_business_data(self.root, 'miniapp', '门店预约')
        self.assertEqual(2, seeded_mini['hw_booking_service'])
        self.assertGreaterEqual(seeded_mini['hw_booking_slot'], 6)

        conn = sqlite3.connect(str(release_db))
        distinct_dates = conn.execute('SELECT COUNT(DISTINCT date) FROM hw_booking_slot').fetchone()[0]
        self.assertEqual(3, distinct_dates)
        conn.close()
        self.assertEqual({}, starter.seed_initial_business_data(self.root, 'miniapp', '门店预约'))
