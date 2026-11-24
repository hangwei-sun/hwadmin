import json
import os
import re
import shutil
import subprocess
import unittest
from pathlib import Path

SCRIPTS_DIR = Path(__file__).resolve().parents[1]
REPO_ROOT = SCRIPTS_DIR.parent


class P01P06ConvergenceTest(unittest.TestCase):
    """P01—P06 快速应用交付与上线收敛核心工程契约测试。"""

    def test_start_here_exists_is_concise_and_lists_three_starters(self):
        start_here = REPO_ROOT / "START_HERE.md"
        self.assertTrue(start_here.is_file(), "根目录缺少 START_HERE.md")
        content = start_here.read_text(encoding="utf-8")
        lines = content.splitlines()
        self.assertLess(len(lines), 60, f"START_HERE.md 篇幅过长 ({len(lines)} 行，要求 < 60 行)")
        for cmd in ("hw start website", "hw start admin", "hw start miniapp"):
            self.assertIn(cmd, content, f"START_HERE.md 缺少一键启动命令: {cmd}")

    def test_readme_first_screen_guides_dev_zip_and_start_here(self):
        readme = REPO_ROOT / "README.md"
        self.assertTrue(readme.is_file(), "根目录缺少 README.md")
        first_screen = "\n".join(readme.read_text(encoding="utf-8").splitlines()[:35])
        self.assertRegex(
            first_screen,
            r"hwadmin(?:-dev)?-v[0-9]+\.[0-9]+\.[0-9]+\.zip",
            "README.md 首屏未明确引导下载完整发行包 (hwadmin-v*.zip)",
        )
        self.assertIn("START_HERE.md", first_screen, "README.md 首屏未引导阅读 START_HERE.md")

    def test_three_templates_sources_and_migrations_exist(self):
        for tpl in ("corporate-site", "enterprise-admin", "booking-system"):
            tpl_dir = REPO_ROOT / "templates" / tpl
            manifest_path = tpl_dir / "template.json"
            self.assertTrue(manifest_path.is_file(), f"模板 {tpl} 缺少 template.json")
            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))

            files = manifest.get("files", [])
            migrations = manifest.get("migrations", [])
            self.assertGreater(len(files), 0, f"模板 {tpl} files 清单为空")
            self.assertGreater(len(migrations), 0, f"模板 {tpl} migrations 清单为空")

            for entry in files:
                src = entry.get("source", "")
                self.assertTrue(src, f"模板 {tpl} 存在空 source 配置: {entry}")
                self.assertTrue((tpl_dir / src).is_file(), f"模板 {tpl} 缺少源文件: {src}")

            for mig in migrations:
                self.assertTrue(
                    (tpl_dir / "migrations" / mig).is_file(),
                    f"模板 {tpl} 缺少迁移文件: migrations/{mig}",
                )

    def test_corporate_site_index_html_has_articles_detail_and_inquiry(self):
        site_html = REPO_ROOT / "templates/corporate-site/frontend/public/site/index.html"
        self.assertTrue(site_html.is_file(), "缺少官网模板前台页面 index.html")
        content = site_html.read_text(encoding="utf-8")
        for endpoint in ("/api/site/articles", "/api/site/detail", "/api/site/submitInquiry"):
            self.assertIn(endpoint, content, f"官网前台 index.html 缺少接口调用: {endpoint}")
        self.assertIn("brand.json", content, "官网前台 index.html 缺少品牌配置 brand.json 加载")

    def test_enterprise_admin_project_vue_has_full_actions_a11y_and_custom_markers(self):
        vue_path = REPO_ROOT / "templates/enterprise-admin/frontend/views/enterprise/project/index.vue"
        self.assertTrue(vue_path.is_file(), "缺少业务管理后台模板视图 project/index.vue")
        content = vue_path.read_text(encoding="utf-8")
        expected_apis = (
            "/adminapi/enterprise.project/index",
            "/adminapi/enterprise.project/add",
            "/adminapi/enterprise.project/edit",
            "/adminapi/enterprise.project/del",
            "/adminapi/enterprise.project/export",
            "/adminapi/enterprise.project/detail",
            "/adminapi/enterprise.project/addAttachment",
            "/adminapi/enterprise.task/add",
            "/adminapi/enterprise.task/edit",
            "/adminapi/enterprise.task/del",
        )
        for api in expected_apis:
            self.assertIn(api, content, f"enterprise/project/index.vue 缺少业务接口调用: {api}")
        for marker in (
            "aria-label",
            "v-auth",
            "<el-empty",
            "@media (max-width: 768px)",
            "@hw-custom-start",
            "@hw-custom-end",
        ):
            self.assertIn(marker, content, f"enterprise/project/index.vue 缺少无障碍或保护标记: {marker}")

    def test_booking_order_vue_has_service_slot_order_apis_a11y_and_custom_markers(self):
        vue_path = REPO_ROOT / "templates/booking-system/frontend/views/booking/order/index.vue"
        self.assertTrue(vue_path.is_file(), "缺少预约后台模板视图 booking/order/index.vue")
        content = vue_path.read_text(encoding="utf-8")
        expected_apis = (
            "/adminapi/booking.order/index",
            "/adminapi/booking.order/transition",
            "/adminapi/booking.order/saveService",
            "/adminapi/booking.order/saveSlot",
            "/api/booking/services",
        )
        for api in expected_apis:
            self.assertIn(api, content, f"booking/order/index.vue 缺少业务接口调用: {api}")
        for marker in (
            "aria-label",
            "v-auth",
            "<el-empty",
            "type ElTagType",
            "@hw-custom-start",
            "@hw-custom-end",
        ):
            self.assertIn(marker, content, f"booking/order/index.vue 缺少无障碍或保护标记: {marker}")

    def test_booking_mobile_entry_and_page_contracts(self):
        mobile_booking = REPO_ROOT / "templates/booking-system/mobile/pages/booking/index.vue"
        self.assertTrue(mobile_booking.is_file(), "缺少预约小程序页面模板")
        booking_txt = mobile_booking.read_text(encoding="utf-8")
        self.assertNotIn("slot_id: 1", booking_txt, "预约小程序页面不得写死 slot_id: 1")
        for token in (
            "/booking/services",
            "/booking/slots",
            "/booking/create",
            "/booking/myOrders",
            "/booking/cancel",
            "uploadAvatar",
            "aria-label",
            "@hw-custom-start",
            "/pages/login/index",
        ):
            self.assertIn(token, booking_txt, f"预约小程序页面缺少契约片段: {token}")

        mobile_home = REPO_ROOT / "admin-ui/mobile/src/pages/index/index.vue"
        self.assertTrue(mobile_home.is_file(), "缺少移动端首页 index.vue")
        self.assertIn(
            "/pages/booking/index",
            mobile_home.read_text(encoding="utf-8"),
            "移动端首页缺少预约页面入口 /pages/booking/index",
        )

    def test_delivery_verification_script_syntax_and_markers(self):
        script_path = SCRIPTS_DIR / "verify-p01-p06-delivery.sh"
        self.assertTrue(script_path.is_file(), "缺少 scripts/verify-p01-p06-delivery.sh")
        content = script_path.read_text(encoding="utf-8")
        self.assertIn("PASSED_P01_P06", content)
        self.assertIn("start-project.py", content)
        self.assertIn("hw-deploy.py", content)
        bash_bin = shutil.which("bash")
        if os.name != "nt" and bash_bin:
            proc = subprocess.run(
                [bash_bin, "-n", str(script_path)],
                capture_output=True,
                text=True,
            )
            self.assertEqual(0, proc.returncode, f"bash -n 语法检查失败: {proc.stderr}")
        elif os.name == "nt" and shutil.which("wsl.exe"):
            wsl_path = script_path.as_posix()
            if len(wsl_path) >= 2 and wsl_path[1] == ":":
                wsl_path = f"/mnt/{wsl_path[0].lower()}{wsl_path[2:]}"
            proc = subprocess.run(
                ["wsl.exe", "-e", "bash", "-n", wsl_path],
                capture_output=True,
            )
            if proc.returncode != 0:
                stderr_txt = proc.stderr.decode("utf-8", errors="replace")
                self.fail(f"WSL bash -n 语法检查失败: {stderr_txt}")


if __name__ == "__main__":
    unittest.main()
