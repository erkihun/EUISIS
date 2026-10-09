"""No database, network, pgBackRest executable or real backup required."""
import json
import pathlib
import tempfile
import unittest
from unittest.mock import patch

import backup


class BackupRunnerTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.cfg = {"pgbackrest": "/usr/bin/pgbackrest", "pgbackrest_config": "/etc/pgbackrest.conf",
                    "stanza": "euisis", "journal_dir": self.directory.name}
        self.good = ("stanza: euisis\nstatus: ok\n"
                     "  archiveId: 18-1, total WAL checked: 2, total valid WAL: 2\n"
                     "    missing: 0, checksum invalid: 0, size invalid: 0, other: 0\n"
                     "  backup: 20260927-010000F, status: valid, total files checked: 3, total valid files: 3\n"
                     "    missing: 0, checksum invalid: 0, size invalid: 0, other: 0\n")

    def test_verify_success_requires_positive_checks(self):
        self.assertTrue(backup.verification_passed(self.good, "euisis"))
        self.assertFalse(backup.verification_passed(self.good, "other"))
        for invalid in ["", "stanza: euisis\nstatus: ok\n  backup: none found", self.good.replace("status: ok", "status: error"),
                        self.good.replace("status: valid", "status: invalid"), self.good.replace("missing: 0", "missing: 1"),
                        self.good.replace("total valid files: 3", "total valid files: 2"), self.good + "unknown result\n"]:
            with self.subTest(output=invalid):
                self.assertFalse(backup.verification_passed(invalid, "euisis"))

    def test_exit_zero_with_corruption_is_failed_and_journal_contains_no_output(self):
        with patch.object(backup, "run", return_value="stanza: euisis\nstatus: error\nsecret=DO_NOT_PERSIST"):
            self.assertFalse(backup.operation(self.cfg, "verify", 1))
        item = json.loads(next(pathlib.Path(self.directory.name).glob("*.json")).read_text())
        self.assertEqual(item["status"], "FAILED")
        self.assertNotIn("DO_NOT_PERSIST", json.dumps(item))

    def test_failed_backup_is_recorded(self):
        with patch.object(backup, "run", side_effect=OSError("secret")):
            self.assertFalse(backup.operation(self.cfg, "full", 2))
        item = json.loads(next(pathlib.Path(self.directory.name).glob("*.json")).read_text())
        self.assertEqual((item["type"], item["repository"], item["status"]), ("FULL_BACKUP", 2, "FAILED"))

    def test_valid_verify_is_recorded(self):
        with patch.object(backup, "run", return_value=self.good):
            self.assertTrue(backup.operation(self.cfg, "verify", 1))

    def test_command_is_fixed_argument_vector(self):
        args = backup.command(self.cfg, 2, "diff")
        self.assertEqual(args[-3:], ["--type=diff", "--no-expire-auto", "backup"])
        self.assertIn("--repo=2", args)
        self.assertIn("--output=text", backup.command(self.cfg, 1, "verify"))
        for action in ("restore", "rm -rf /", "full; echo injection"):
            with self.assertRaises(ValueError):
                backup.command(self.cfg, 1, action)
        with self.assertRaises(ValueError):
            backup.command(self.cfg, "1; echo injection", "full")

    def test_config_rejects_stanza_injection(self):
        path = pathlib.Path(self.directory.name) / "config.json"
        path.write_text(json.dumps({**self.cfg, "stanza": "euisis; echo injected"}))
        with self.assertRaises(ValueError):
            backup.load_config(str(path))

    def test_info_selects_only_requested_repo_and_excludes_raw_secrets(self):
        info = [{"name": "euisis", "repo": [{"key": 2, "status": {"code": 0}}], "secret": "hidden",
                 "backup": [{"database": {"repo-key": 2}, "label": "20260927-010000F", "type": "full",
                             "timestamp": {"stop": 1234}, "info": {"size": 100}, "annotation": {"password": "hidden"}}]}]
        with patch.object(backup, "run", return_value=json.dumps(info)):
            result = backup.repository(self.cfg, 2)
        self.assertTrue(result["available"])
        self.assertEqual(len(result["backups"]), 1)
        self.assertNotIn("hidden", json.dumps(result))
        info[0]["backup"][0]["error"] = True
        with patch.object(backup, "run", return_value=json.dumps(info)):
            self.assertFalse(backup.repository(self.cfg, 2)["available"])

    def test_non_pgbackrest_actions_never_become_pgbackrest_commands(self):
        for action in ("logical", "restore-test"):
            with self.assertRaises(ValueError):
                backup.command(self.cfg, 1, action)

    def test_failed_logical_backup_is_journaled_outside_repositories(self):
        with patch.object(backup, "logical_backup", side_effect=RuntimeError("password=DO_NOT_PERSIST")):
            self.assertFalse(backup.operation(self.cfg, "logical", 1))
        item = json.loads(next(pathlib.Path(self.directory.name).glob("*.json")).read_text())
        self.assertEqual((item["type"], item["repository"], item["status"]), ("LOGICAL_BACKUP", None, "FAILED"))
        self.assertNotIn("DO_NOT_PERSIST", json.dumps(item))

    def test_logical_backup_rejects_shell_string_encryptor_before_any_process(self):
        cfg = {**self.cfg, "logical_backup": {"pg_dump": "/usr/bin/pg_dump", "postgres_service": "euisis_logical",
                                              "output_dir": self.directory.name, "retain_count": 3,
                                              "encrypt_command": "gpg -e | tee /tmp/leak"}}
        with patch.object(backup.subprocess, "Popen") as popen:
            with self.assertRaises(ValueError):
                backup.logical_backup(cfg)
            popen.assert_not_called()

    def test_logical_prune_keeps_newest_and_only_touches_own_archives(self):
        directory = pathlib.Path(self.directory.name)
        names = [f"euisis-2026090{day}-010000.dump.enc" for day in range(1, 6)]
        for name in names + ["20260901-010000F.manifest", "notes.txt", ".euisis-20260906-010000.dump.enc.partial"]:
            (directory / name).write_text("x")
        backup.prune_logical(directory, 2)
        remaining = sorted(p.name for p in directory.iterdir())
        self.assertEqual(remaining, sorted(names[-2:] + ["20260901-010000F.manifest", "notes.txt", ".euisis-20260906-010000.dump.enc.partial"]))
        with self.assertRaises(ValueError):
            backup.prune_logical(directory, 0)

    def test_isolated_restore_refuses_without_marker_before_any_process(self):
        import restore_test
        with patch.object(restore_test, "run") as runner:
            with self.assertRaises((ValueError, FileNotFoundError)):
                restore_test.restore_test({**self.cfg, "restore_test": {"isolated": False, "root": "/"}}, 1)
            runner.assert_not_called()


if __name__ == "__main__":
    unittest.main()
