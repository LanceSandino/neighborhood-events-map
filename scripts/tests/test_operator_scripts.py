"""Regression tests for the operator shell scripts and the image build context (no Docker needed).

@module test_operator_scripts
@description Runs the refusal paths of docker/php/entrypoint.sh and bin/restore.sh in a subprocess
    (they stop before any container or database is touched), one pass of the backup loop against a
    stand-in pg_dump, and checks that every private, git-ignored deployment file is also kept out of the
    Docker build context.
@dependencies unittest, subprocess, tempfile
"""
import os
import subprocess
import tempfile
import time
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


class BackupLoopTests(unittest.TestCase):
    """Public-readiness hardening note: interrupted backups left .partial files that never rotated."""

    def run_once(self, folder: Path, pg_dump_exit: int) -> subprocess.CompletedProcess:
        bin_dir = folder / "bin"
        bin_dir.mkdir(exist_ok=True)
        fake = bin_dir / "pg_dump"  # writes its --file argument, like a real dump, then exits as told
        fake.write_text('#!/bin/sh\nfor a in "$@"; do case "$a" in --file=*) echo dump > "${a#--file=}";; esac; done\n'
                        f"exit {pg_dump_exit}\n")
        fake.chmod(0o755)
        return run(["sh", "docker/postgres/backup-loop.sh"],
                   {"PATH": f"{bin_dir}:/usr/bin:/bin", "BACKUP_PATH": str(folder / "backups"), "BACKUP_ONCE": "1"})

    def test_a_pass_removes_stale_partials_and_old_dumps_and_keeps_the_rest(self):
        with tempfile.TemporaryDirectory() as tmp:
            backups = Path(tmp) / "backups"
            backups.mkdir()
            hours_ago = lambda h: time.time() - h * 3600
            files = {".halloween-20260101T000000Z.dump.partial": hours_ago(3),   # cut off by a stop: removed
                     ".halloween-20260102T000000Z.dump.partial": hours_ago(0),   # being written right now: kept
                     "halloween-20250101T000000Z.dump": hours_ago(24 * 40),      # past retention: removed
                     "halloween-20260103T000000Z.dump": hours_ago(24)}           # recent: kept
            for name, mtime in files.items():
                (backups / name).write_text("x")
                os.utime(backups / name, (mtime, mtime))
            r = self.run_once(Path(tmp), 0)
            self.assertEqual(r.returncode, 0, r.stderr)
            left = sorted(p.name for p in backups.iterdir())
            self.assertNotIn(".halloween-20260101T000000Z.dump.partial", left)
            self.assertIn(".halloween-20260102T000000Z.dump.partial", left)
            self.assertNotIn("halloween-20250101T000000Z.dump", left)
            self.assertIn("halloween-20260103T000000Z.dump", left)
            new = [n for n in left if n.startswith("halloween-") and n not in files]
            self.assertEqual(len(new), 1, left)
            self.assertEqual((backups / new[0]).read_text(), "dump\n")
            self.assertEqual(oct(backups.stat().st_mode & 0o777), "0o700")

    def test_a_failed_dump_leaves_nothing_behind(self):
        with tempfile.TemporaryDirectory() as tmp:
            r = self.run_once(Path(tmp), 1)
            self.assertEqual(r.returncode, 0)
            self.assertIn("backup FAILED", r.stderr)
            self.assertEqual(list((Path(tmp) / "backups").iterdir()), [])

    def test_backup_now_also_writes_a_partial_file_first(self):
        script = (ROOT / "bin" / "backup-now.sh").read_text()
        self.assertIn(".halloween-$stamp.dump.partial", script)
        self.assertIn("mv \\$t /backups/halloween-$stamp.dump", script)


class UpgradePostgresTests(unittest.TestCase):
    """bin/upgrade-postgres.sh decides from the running server's major version and compose.yaml's image; a stand-in
    docker command answers those two questions, so the refusal paths run without Docker."""

    def run_with(self, image: str, running: str | None) -> subprocess.CompletedProcess:
        with tempfile.TemporaryDirectory() as tmp:
            fake = Path(tmp) / "docker"
            version = f'echo "postgres (PostgreSQL) {running}"' if running else "exit 1"
            fake.write_text("#!/bin/sh\n"
                            'case "$*" in\n'
                            f'  *"config --images postgres"*) echo "{image}";;\n'
                            f'  *"exec -T postgres postgres --version"*) {version};;\n'
                            '  *) echo "unexpected: $*" >&2; exit 9;;\n'
                            "esac\n")
            fake.chmod(0o755)
            return run(["bash", "bin/upgrade-postgres.sh"], {"PATH": f"{tmp}:/usr/bin:/bin"})

    def test_nothing_happens_when_the_running_server_is_already_the_target(self):
        r = self.run_with("postgres:18-alpine@sha256:" + "0" * 64, "18.6")
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertIn("already PostgreSQL 18", r.stdout)
        self.assertNotIn("unexpected", r.stderr, "nothing was stopped, dumped or started")

    def test_it_refuses_without_a_running_server_to_dump(self):
        r = self.run_with("postgres:18-alpine", None)
        self.assertEqual(r.returncode, 2)
        self.assertIn("not running", r.stderr)

    def test_it_asks_before_touching_anything(self):
        r = self.run_with("postgres:18-alpine", "17.11")  # stdin is empty: no UPGRADE answer
        self.assertEqual(r.returncode, 1)
        self.assertIn("Aborted", r.stdout)
        self.assertNotIn("unexpected", r.stderr, "nothing was stopped before the answer")


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
