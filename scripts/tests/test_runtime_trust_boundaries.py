import http.server
import importlib.util
import tempfile
import threading
import unittest
from pathlib import Path


spec = importlib.util.spec_from_file_location('runtime_boundary', Path(__file__).resolve().parents[1] / 'hw-runtime.py')
runtime = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runtime)


class RuntimeTrustBoundaryTest(unittest.TestCase):
    def test_runtime_minimum_matches_frontend_engine_requirement(self):
        self.assertFalse(runtime._node_meets_minimum((20, 19, 1)))
        self.assertFalse(runtime._node_meets_minimum((22, 17, 0)))
        self.assertTrue(runtime._node_meets_minimum((22, 18, 0)))

    def test_manifest_paths_cannot_escape_managed_directory(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for value in ('../outside', '/tmp/outside', 'C:/outside', 'name:stream', 'a\\outside', '.'):
                with self.subTest(value=value), self.assertRaises(ValueError):
                    runtime._confined_runtime_path(root, value)
            for value in ('../php', '/php', 'a/b', '..', 'a:stream'):
                with self.subTest(component=value), self.assertRaises(ValueError):
                    runtime._component_name(value)

    def test_bad_entry_keeps_existing_component_and_external_file(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            stage = root / 'stage'
            stage.mkdir()
            (stage / 'php').write_text('new')
            managed = root / 'managed'
            old = managed / 'components/php/php'
            old.parent.mkdir(parents=True)
            old.write_text('working')
            external = root / 'external'
            external.write_text('untouched')
            for entry in ('missing', str(external.resolve()), '../external'):
                with self.subTest(entry=entry), self.assertRaises(ValueError):
                    runtime._install_component_from_staging(stage, managed, 'php', [entry])
                self.assertEqual('working', old.read_text())
                self.assertEqual('untouched', external.read_text())
                self.assertTrue(stage.exists())

    def test_redirect_must_pass_source_allowlist_before_following(self):
        class Redirect(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                self.send_response(302)
                self.send_header('Location', 'https://untrusted.example.invalid/runtime.zip')
                self.end_headers()

            def log_message(self, *args):
                pass

        server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Redirect)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            with tempfile.TemporaryDirectory() as directory, self.assertRaises(ValueError):
                runtime.download_with_retries(f'http://127.0.0.1:{server.server_port}/runtime.zip',
                                              Path(directory) / 'runtime.zip', retries=1)
        finally:
            server.shutdown()
            server.server_close()
            thread.join()
