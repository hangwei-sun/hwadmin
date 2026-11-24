import importlib.util
import re
import sys
from pathlib import Path
import unittest

SCRIPTS_DIR = Path(__file__).resolve().parents[1]
REPO_ROOT = SCRIPTS_DIR.parent
if str(SCRIPTS_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_DIR))

spec_release = importlib.util.spec_from_file_location('build_release', SCRIPTS_DIR / 'build-release.py')
release = importlib.util.module_from_spec(spec_release)
spec_release.loader.exec_module(release)

spec_backup = importlib.util.spec_from_file_location('backup_restore', SCRIPTS_DIR / 'backup_restore.py')
backup = importlib.util.module_from_spec(spec_backup)
spec_backup.loader.exec_module(backup)


class V150PackagesAndDocsTest(unittest.TestCase):
    def test_root_agents_is_under_80_lines_and_docs_layout_is_clean(self):
        agents = REPO_ROOT / 'AGENTS.md'
        lines = agents.read_text(encoding='utf-8').splitlines()
        self.assertLess(len(lines), 80, f'根目录 AGENTS.md 应小于 80 行，实际 {len(lines)} 行')

        schema = REPO_ROOT / 'server/core/support/hw.project.schema.json'
        self.assertTrue(schema.is_file(), '缺少内部协议文件 server/core/support/hw.project.schema.json')
        for hidden_dir in ('docs/maintainers', 'docs/schemas', 'docs/conventions', 'docs/plugin'):
            self.assertFalse((REPO_ROOT / hidden_dir).exists(), f'公开 docs 目录不应保留内部或嵌套子目录: {hidden_dir}')
        hwadmin_cfg = (REPO_ROOT / 'server/config/hwadmin.php').read_text(encoding='utf-8')
        self.assertIn('https://hw.baotoutv.cn', hwadmin_cfg)

    def test_root_agent_docs_have_no_broken_relative_links(self):
        for doc_name in ('AGENTS.md', 'CLAUDE.md', 'START_HERE.md'):
            doc = REPO_ROOT / doc_name
            text = doc.read_text(encoding='utf-8')
            for target in re.findall(r'\[[^\]]+\]\(([^)#\s]+)\)', text):
                if target.startswith(('http://', 'https://', 'mailto:')):
                    continue
                resolved = REPO_ROOT / target.removeprefix('./')
                self.assertTrue(
                    resolved.exists(),
                    f'{doc_name} 引用了不存在的路径: {target}',
                )

    def test_deploy_package_docs_contain_no_dev_only_dead_links(self):
        docs = release.render_deploy_docs('v1.5.0')
        self.assertEqual(set(docs.keys()), {'README.md', 'AGENTS.md', 'CLAUDE.md'})
        for name, content in docs.items():
            for forbidden in ('START_HERE.md', './hw', 'wsl-dev.sh', 'docs/conventions/', 'docs/adr/', 'tests/'):
                self.assertNotIn(forbidden, content, f'deploy 包 {name} 不应包含开发包死链: {forbidden}')
            self.assertIn('hw:doctor --mode=prod --json', docs['AGENTS.md'])

    def test_sqlite_default_unified_to_runtime_hwadmin_sqlite(self):
        old_server = backup.SERVER
        try:
            backup.SERVER = REPO_ROOT / 'server'
            resolved = backup.sqlite_path({})
            self.assertEqual(resolved.as_posix().endswith('/runtime/hwadmin.sqlite'), True)
        finally:
            backup.SERVER = old_server

        app_cfg = (REPO_ROOT / 'server/config/app.php').read_text(encoding='utf-8')
        self.assertRegex(app_cfg, r"'app_express'\s*=>\s*true")
        env_candidates = [
            REPO_ROOT / 'server/.example.env',
            REPO_ROOT / 'server/.env.dev.sqlite.example',
        ]
        existing_envs = [p for p in env_candidates if p.is_file()]
        self.assertTrue(existing_envs, '缺少 server/.example.env 或 server/.env.dev.sqlite.example')
        for env_file in existing_envs:
            self.assertIn(
                'DB_NAME = runtime/hwadmin.sqlite',
                env_file.read_text(encoding='utf-8'),
                f'{env_file.name} 未声明 DB_NAME = runtime/hwadmin.sqlite',
            )

    def test_baota_nginx_plan_uses_server_public_for_unified_layout_and_start_project_resolves_hwadmin_sqlite(self):
        import tempfile

        spec_deploy = importlib.util.spec_from_file_location('hw_deploy', SCRIPTS_DIR / 'hw-deploy.py')
        deploy = importlib.util.module_from_spec(spec_deploy)
        spec_deploy.loader.exec_module(deploy)

        spec_start = importlib.util.spec_from_file_location('start_project', SCRIPTS_DIR / 'start-project.py')
        start_mod = importlib.util.module_from_spec(spec_start)
        spec_start.loader.exec_module(start_mod)

        with tempfile.TemporaryDirectory(prefix='hw-v1515-layout-') as td:
            root = Path(td)
            adapter = deploy.BaotaNginxDeployAdapter(root)
            unified_plan = adapter.generate_plan({'artifact_type': 'unified', 'filename': 'hwadmin-v1.5.15.zip'})
            self.assertEqual(unified_plan['configs']['public_dir'], '/www/wwwroot/hwadmin/server/public')
            self.assertIn('/www/wwwroot/hwadmin/server/.env', unified_plan['persistent_protection'])

            legacy_plan = adapter.generate_plan({'artifact_type': 'deploy', 'filename': 'hwadmin-release-v1.4.2.zip'})
            self.assertEqual(legacy_plan['configs']['public_dir'], '/www/wwwroot/hwadmin/public')

            self.assertTrue(deploy.should_skip_code_snapshot('admin-ui/src-tauri/target/debug/hwadmin.exe'))
            self.assertFalse(deploy.should_skip_code_snapshot('admin-ui/src/views/index.vue'))

            (root / 'server/runtime').mkdir(parents=True, exist_ok=True)
            hwadmin_db = root / 'server/runtime/hwadmin.sqlite'
            hwadmin_db.write_bytes(b'sqlite-test')
            self.assertEqual(start_mod._resolve_sqlite_db(root), hwadmin_db)

    def test_release_doc_whitelist_excludes_internal_progress_and_notes(self):
        allowed = release.DEV_PACKAGE_ALLOWED_DOCS
        self.assertIn('docs/部署与运维指南.md', allowed)
        self.assertIn('docs/后端开发规范.md', allowed)
        self.assertIn('docs/前端开发规范.md', allowed)
        self.assertIn('docs/插件系统规范.md', allowed)
        for excluded in (
            'docs/开发进展看板.md',
            'docs/产品与开发路线图.md',
            'docs/历史评审整改开发记录.md',
            'docs/上线前深度调研报告.md',
            'docs/1.5.15发布说明.md',
            'docs/SBOM与合规清单.md',
            'docs/THIRD_PARTY_NOTICES.md',
            'docs/安装与部署安全检查.md',
            'docs/maintainers/发版与维护规范.md',
            'docs/schemas/hw.project.schema.json',
        ):
            self.assertNotIn(excluded, allowed)

        start_here = (REPO_ROOT / 'START_HERE.md').read_text(encoding='utf-8')
        self.assertIn('标准项目需求与部署模板', start_here)


if __name__ == '__main__':
    unittest.main()
