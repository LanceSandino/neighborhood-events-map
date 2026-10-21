"""Regression tests for the operator shell scripts and the image build context (no Docker needed).

@module test_operator_scripts
@description Runs the refusal paths of docker/php/entrypoint.sh and bin/restore.sh in a subprocess
    (they stop before any container or database is touched) and checks that every private,
    git-ignored deployment file is also kept out of the Docker build context.
@dependencies unittest, subprocess
"""
import os
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
STRONG_SECRET = "0123456789abcdef" * 3  # shape only; never a real secret


def run(cmd: list[str], env: dict[str, str]) -> subprocess.CompletedProcess:
    base = {"PATH": os.environ.get("PATH", "/usr/bin:/bin")}
    return subprocess.run(cmd, cwd=ROOT, env={**base, **env}, stdin=subprocess.DEVNULL,
                          capture_output=True, text=True, timeout=20)


class EntrypointTests(unittest.TestCase):
    def prod_env(self, **extra: str) -> dict[str, str]:
        return {"APP_ENV": "prod", "APP_SECRET": STRONG_SECRET,
                "DATABASE_URL": "postgresql://app:" + "s3cret-" * 4 + "@postgres:5432/app", **extra}

    def test_debug_mode_is_refused_in_production(self):
        # Security audit hardening note: APP_DEBUG=1 would show stack traces and secrets to visitors.
        r = run(["sh", "docker/php/entrypoint.sh", "true"], self.prod_env(APP_DEBUG="1"))
        self.assertEqual(r.returncode, 64, r.stderr)
        self.assertIn("APP_DEBUG", r.stderr)

    def test_weak_secrets_are_still_refused(self):
        r = run(["sh", "docker/php/entrypoint.sh", "true"], self.prod_env(APP_SECRET="change-me"))
        self.assertEqual(r.returncode, 64)
        r = run(["sh", "docker/php/entrypoint.sh", "true"],
                self.prod_env(DATABASE_URL="postgresql://halloween:" + "halloween" + "@postgres:5432/halloween"))
        self.assertEqual(r.returncode, 64)
        self.assertIn("POSTGRES_PASSWORD", r.stderr)


class RestoreTests(unittest.TestCase):
    def test_backup_names_that_could_run_shell_commands_are_refused(self):
        # Security audit hardening note: the name is used inside "sh -c" in the backup container.
        for name in ["x.dump; rm -rf /backups", "$(id).dump", "../etc/passwd", "a b.dump", "backup.sql"]:
            r = run(["bash", "bin/restore.sh", name], {})
            self.assertNotEqual(r.returncode, 0, name)
            self.assertIn("backup file name", r.stderr, name)
            self.assertNotIn("Type RESTORE", r.stdout + r.stderr, "refused before asking: " + name)


class GitIgnoreTests(unittest.TestCase):
    def test_the_whole_deployment_folder_is_ignored(self):
        # The README tells operators to keep their community files in deploy/community/: never committable.
        entries = {line.strip() for line in (ROOT / ".gitignore").read_text().splitlines()
                   if line.strip() and not line.startswith("#")}
        for required in ["/deploy/", "/backups/", ".env", ".env.*", "/config/community.yaml",
                         "/config/boundary.geojson", "/config/zones.geojson"]:
            self.assertIn(required, entries, required)


class BuildContextTests(unittest.TestCase):
    def test_private_deployment_files_never_enter_the_image(self):
        # Security audit hardening note: the prod image copies the whole checkout (COPY . .).
        entries = {line.strip() for line in (ROOT / ".dockerignore").read_text().splitlines()
                   if line.strip() and not line.startswith("#")}
        for required in [".env", ".env.*", "deploy", "backups", "config/community.yaml", "config/*.geojson"]:
            self.assertIn(required, entries, required)


if __name__ == "__main__":
    unittest.main()
