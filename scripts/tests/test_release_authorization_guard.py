import importlib.util
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "build-release.py"
spec = importlib.util.spec_from_file_location("build_release", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class AuthorizationGuardTest(unittest.TestCase):
    def test_rejects_known_runtime_authorization_and_remote_dumper(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            js = root / "app.js"
            js.write_bytes(b'const required = "vite-plugin-vitebar";')
            files = [
                (js, "public/admin/static/js/app.js", js.stat().st_size),
                (root / "Connection.php", "vendor/topthink/think-dumper/src/Connection.php", 0),
            ]
            self.assertEqual(
                module.find_forbidden_authorization(files),
                ["public/admin/static/js/app.js", "vendor/topthink/think-dumper/src/Connection.php"],
            )

    def test_allows_local_authentication_and_license_documents(self):
        with tempfile.TemporaryDirectory() as directory:
            js = Path(directory) / "app.js"
            js.write_bytes(b'fetch("/adminapi/index/login");')
            self.assertEqual(module.find_forbidden_authorization([(js, "public/admin/app.js", js.stat().st_size)]), [])


if __name__ == "__main__":
    unittest.main()
