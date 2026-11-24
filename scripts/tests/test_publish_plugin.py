import json
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path

SCRIPTS_DIR = Path(__file__).resolve().parents[1]
if str(SCRIPTS_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_DIR))

import publish_plugin as pp


class PublishPluginRuleTest(unittest.TestCase):
    def test_semver_bump_aligned_with_framework_rules(self) -> None:
        self.assertEqual(pp.bump_semver("2.0.0", "patch"), "2.0.1")
        self.assertEqual(pp.bump_semver("2.0.3", "minor"), "2.1.0")
        self.assertEqual(pp.bump_semver("v1.5.19", "patch"), "1.5.20")
        self.assertEqual(pp.bump_semver("1.5.19", "minor"), "1.6.0")

        with self.assertRaises(ValueError):
            pp.bump_semver("2.0.0", "major", allow_major=False)

        self.assertEqual(pp.bump_semver("2.0.0", "major", allow_major=True), "3.0.0")

    def test_max_semver_monotonic_base(self) -> None:
        self.assertEqual(pp.max_semver("1.1.0", "2.0.0", "1.0.5"), "2.0.0")
        self.assertEqual(pp.max_semver("2.0.0", "2.1.0", None), "2.1.0")
        self.assertEqual(pp.max_semver(None, "v1.2.3"), "1.2.3")

    def test_build_plugin_zip_places_manifest_at_root_and_skips_runtime_files(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            src = tmp_path / "src_cms"
            (src / "public").mkdir(parents=True)
            (src / "plugin.json").write_text(
                json.dumps({"name": "cms", "version": "2.1.0"}, ensure_ascii=False),
                encoding="utf-8",
            )
            (src / "public" / "index.html").write_text("<html>ok</html>", encoding="utf-8")
            (src / ".env").write_text("SECRET=1", encoding="utf-8")
            (src / "bad.sqlite").write_text("db", encoding="utf-8")

            out_dir = tmp_path / "out"
            info = pp.build_plugin_zip(src, out_dir, "cms", "2.1.0")

            self.assertEqual(info["zip_filename"], "cms-v2.1.0.zip")
            self.assertTrue(info["zip_path"].is_file())
            self.assertTrue(info["alt_path"].is_file())

            with zipfile.ZipFile(info["zip_path"], "r") as zf:
                names = set(zf.namelist())
                self.assertIn("plugin.json", names)
                self.assertIn("public/index.html", names)
                self.assertNotIn(".env", names)
                self.assertNotIn("bad.sqlite", names)


if __name__ == "__main__":
    unittest.main()
