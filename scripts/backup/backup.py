#!/usr/bin/env python3
"""Infrastructure-only pgBackRest runner and allowlisted status publisher. No shell."""
import argparse
import json
import os
import pathlib
import re
import shutil
import subprocess
import sys
import tempfile
import time
import uuid

TYPES = {"full": "FULL_BACKUP", "diff": "DIFFERENTIAL_BACKUP", "incr": "INCREMENTAL_BACKUP",
         "verify": "VERIFY", "expire": "RETENTION_CLEANUP", "restore-test": "RESTORE_TEST", "logical": "LOGICAL_BACKUP"}
PGBACKREST_ACTIONS = ("full", "diff", "incr", "verify", "expire", "info", "check")
LABEL = re.compile(r"\d{8}-\d{6}F(?:_\d{8}-\d{6}[DI])?\Z")
LOGICAL_NAME = re.compile(r"euisis-\d{8}-\d{6}\.dump\.enc\Z")
WAL_SQL = """SELECT json_build_object(
 'enabled', current_setting('archive_mode') IN ('on','always'),
 'last_archived_at', extract(epoch from last_archived_time)::bigint,
 'failed_count', failed_count,
 'failing', last_failed_time IS NOT NULL AND (last_archived_time IS NULL OR last_failed_time > last_archived_time),
 'backlog', (SELECT count(*) FROM pg_ls_archive_statusdir() WHERE name LIKE '%.ready'))
 FROM pg_stat_archiver;"""


def run(args, *, input=None, timeout=120):
    # Never log command text, stderr, environment, connection strings or raw info.
    return subprocess.run(args, input=input, text=True, capture_output=True, timeout=timeout, check=True).stdout


def atomic_json(path, data):
    path = pathlib.Path(path)
    if not path.parent.is_dir():
        raise ValueError("Provision the protected output directory first")
    fd, temporary = tempfile.mkstemp(prefix=".report-", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            json.dump(data, stream, separators=(",", ":"))
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, 0o640)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def load_config(path):
    cfg = json.loads(pathlib.Path(path).read_text(encoding="utf-8"))
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_-]{0,62}", cfg["stanza"]):
        raise ValueError("Invalid stanza")
    for name in ("pgbackrest", "pgbackrest_config", "psql", "report_path", "journal_dir"):
        if not pathlib.Path(cfg[name]).is_absolute():
            raise ValueError("Absolute infrastructure paths required")
    if not re.fullmatch(r"[A-Za-z0-9_-]+", cfg["postgres_service"]):
        raise ValueError("Invalid service identifier")
    return cfg


def command(cfg, repo, action):
    if repo not in (1, 2) or action not in PGBACKREST_ACTIONS:
        raise ValueError("Invalid fixed operation")
    args = [cfg["pgbackrest"], "--config=" + cfg["pgbackrest_config"], "--stanza=" + cfg["stanza"],
            "--repo=" + str(repo), "--log-level-console=off", "--log-level-stderr=off"]
    if action in ("full", "diff", "incr"):
        return args + ["--type=" + action, "--no-expire-auto", "backup"]
    if action == "info":
        args += ["--output=json"]
    if action == "verify":
        args += ["--output=text", "--verbose"]
    return args + [action]


def verification_passed(output, stanza):
    # pgBackRest can return exit 0 even when verify reports corruption. Parse the
    # documented text result, fail closed on new/unknown formats and empty repositories.
    lines = output.strip().splitlines()
    if len(lines) < 4 or lines[:2] != ["stanza: " + stanza, "status: ok"]:
        return False
    backups = 0
    archives = 0
    for line in lines[2:]:
        if line == "    missing: 0, checksum invalid: 0, size invalid: 0, other: 0":
            continue
        archive = re.fullmatch(r"  archiveId: [0-9.]+-[0-9]+, total WAL checked: (\d+), total valid WAL: (\d+)", line)
        backup = re.fullmatch(r"  backup: (\d{8}-\d{6}F(?:_\d{8}-\d{6}[DI])?), status: valid, total files checked: (\d+), total valid files: (\d+)", line)
        if archive and int(archive[1]) > 0 and archive[1] == archive[2]:
            archives += 1
        elif backup and int(backup[2]) > 0 and backup[2] == backup[3]:
            backups += 1
        else:
            return False
    return backups > 0 and archives > 0


def prune_logical(directory, keep):
    # Only this runner's own completed archive names directly inside its own directory.
    # Never pointed at a pgBackRest repository: pgBackRest expiry owns chain/WAL retention.
    if keep < 1:
        raise ValueError("Logical retention must keep at least one archive")
    archives = sorted((p for p in directory.iterdir() if LOGICAL_NAME.fullmatch(p.name) and not p.is_symlink() and p.is_file()),
                      key=lambda p: p.name, reverse=True)
    for stale in archives[keep:]:
        stale.unlink()


def logical_backup(cfg):
    """Supplementary portable archive. Never the primary recovery path; PITR uses pgBackRest."""
    lb = cfg["logical_backup"]
    out = pathlib.Path(lb["output_dir"])
    encrypt = lb["encrypt_command"]
    # Public-key encryption on stdin/stdout (e.g. age/gpg recipients). The decryption key is
    # escrowed elsewhere and must never exist on this host or beside the archives.
    if not isinstance(encrypt, list) or not encrypt or not all(isinstance(a, str) for a in encrypt)             or not pathlib.Path(encrypt[0]).is_absolute():
        raise ValueError("Encryption must be a fixed absolute argument vector")
    if not pathlib.Path(lb["pg_dump"]).is_absolute() or not re.fullmatch(r"[A-Za-z0-9_-]+", lb["postgres_service"]):
        raise ValueError("Invalid logical backup configuration")
    if not out.is_absolute() or out.is_symlink() or not out.is_dir() or out.stat().st_mode & 0o077:
        raise ValueError("Logical archive directory must be private")
    name = time.strftime("euisis-%Y%m%d-%H%M%S.dump.enc", time.gmtime())
    partial = out / ("." + name + ".partial")
    timeout = int(cfg.get("operation_timeout_seconds", 86400))
    processes = []
    try:
        with os.fdopen(os.open(partial, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), "wb") as target:
            dump = subprocess.Popen([lb["pg_dump"], "--format=custom", "--no-password", "--dbname=service=" + lb["postgres_service"]],
                                    stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
            processes.append(dump)
            processes.append(subprocess.Popen(encrypt, stdin=dump.stdout, stdout=target, stderr=subprocess.DEVNULL))
            dump.stdout.close()
            if processes[1].wait(timeout=timeout) != 0 or dump.wait(timeout=timeout) != 0:
                raise RuntimeError("Logical backup failed")
            target.flush()
            os.fsync(target.fileno())
        os.replace(partial, out / name)
    finally:
        for process in processes:
            if process.poll() is None:
                process.kill()
        partial.unlink(missing_ok=True)
    prune_logical(out, int(lb["retain_count"]))
    return {"backup_size": (out / name).stat().st_size}


def repository(cfg, repo):
    result = {"id": repo, "available": False, "backups": [], "usage_percent": None}
    try:
        info = json.loads(run(command(cfg, repo, "info")))
        stanza = next(item for item in info if item["name"] == cfg["stanza"])
        repository_info = next(item for item in stanza["repo"] if item["key"] == repo)
        result["available"] = repository_info["status"]["code"] == 0
        for item in stanza.get("backup", []):
            if item["database"]["repo-key"] != repo or not LABEL.fullmatch(item["label"]):
                continue
            # pgBackRest exposes backup error/checksum state; never treat it as usable.
            if item.get("error"):
                result["available"] = False
                continue
            if item["type"] not in ("full", "diff", "incr"):
                continue
            result["backups"].append({"reference": item["label"], "type": item["type"],
                "completed_at": item["timestamp"]["stop"], "size": item["info"]["size"]})
        result["backups"] = sorted(result["backups"], key=lambda b: b["completed_at"], reverse=True)[:500]
        # Only valid when this is the actual repository filesystem/mount. Remote repositories
        # need a capacity report collected on that host, never the primary's disk usage.
        capacity_path = cfg.get("capacity_paths", {}).get(str(repo))
        if capacity_path:
            usage = shutil.disk_usage(capacity_path)
            result["usage_percent"] = round(100 * usage.used / usage.total, 2)
        capacity_report = cfg.get("capacity_reports", {}).get(str(repo))
        if capacity_report:
            capacity = json.loads(pathlib.Path(capacity_report).read_text(encoding="utf-8"))
            if 0 <= time.time() - capacity["observed_at"] <= 900 and 0 <= capacity["usage_percent"] <= 100:
                result["usage_percent"] = capacity["usage_percent"]
    except (KeyError, StopIteration, ValueError, OSError, subprocess.SubprocessError):
        result["available"] = False
    return result


def collect(cfg):
    report = {"version": 1, "observed_at": int(time.time()), "database": "UNKNOWN",
              "repositories": [repository(cfg, 1), repository(cfg, 2)],
              "wal": {"enabled": False, "last_archived_at": None, "failed_count": 0, "failing": False, "backlog": None},
              "operations": [], "controls": {}, "controls_checked_at": None}
    try:
        report["wal"] = json.loads(run([cfg["psql"], "-X", "-w", "-A", "-t", "-v", "ON_ERROR_STOP=1",
                                        "dbname=postgres service=" + cfg["postgres_service"]], input=WAL_SQL))
        report["database"] = "HEALTHY"
    except (ValueError, OSError, subprocess.SubprocessError):
        report["database"] = "CRITICAL"
    paths = sorted(pathlib.Path(cfg["journal_dir"]).glob("*.json"), key=lambda p: p.stat().st_mtime, reverse=True)[:1000]
    for path in paths:
        item = json.loads(path.read_text(encoding="utf-8"))
        report["operations"].append({k: item.get(k) for k in ("id", "type", "status", "repository", "started_at", "completed_at", "backup_reference", "backup_size", "recovery_target")})
    if cfg.get("controls_file"):
        evidence = json.loads(pathlib.Path(cfg["controls_file"]).read_text(encoding="utf-8"))
        report["controls_checked_at"] = evidence.get("checked_at")
        for key in ("independent_copy", "encrypted", "private_storage", "key_recovery", "file_recovery", "retention_configured", "owner_assigned", "alerts_tested", "runbooks_reviewed"):
            report["controls"][key] = evidence.get(key) is True
    atomic_json(cfg["report_path"], report)
    return report


def operation(cfg, action, repo):
    item = {"id": str(uuid.uuid4()), "type": TYPES[action], "status": "RUNNING", "repository": None if action == "logical" else repo,
            "started_at": int(time.time()), "completed_at": None}
    journal = pathlib.Path(cfg["journal_dir"]) / (item["id"] + ".json")
    atomic_json(journal, item)
    try:
        if action == "restore-test":
            from restore_test import restore_test
            item.update(restore_test(cfg, repo))
        elif action == "logical":
            item.update(logical_backup(cfg))
        else:
            output = run(command(cfg, repo, action), timeout=int(cfg.get("operation_timeout_seconds", 86400)))
            if action == "verify" and not verification_passed(output, cfg["stanza"]):
                raise ValueError("Repository verification did not prove integrity")
            if action in ("full", "diff", "incr"):
                backups = repository(cfg, repo)["backups"]
                if backups:
                    item["backup_reference"] = backups[0]["reference"]
                    item["backup_size"] = backups[0]["size"]
        item["status"] = "SUCCEEDED"
    except Exception:
        item["status"] = "FAILED"
    finally:
        item["completed_at"] = int(time.time())
        atomic_json(journal, item)
    return item["status"] == "SUCCEEDED"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=[*TYPES, "collect", "check"])
    parser.add_argument("--config", required=True)
    parser.add_argument("--repo", type=int, choices=[1, 2], default=1)
    args = parser.parse_args()
    try:
        os.umask(0o077)
        cfg = load_config(args.config)
        if args.action == "collect":
            collect(cfg)
            success = True
        elif args.action == "check":
            run(command(cfg, args.repo, "check"))
            success = True
        else:
            # Serialize infrastructure operations independently of Laravel. Locks release on crash.
            import fcntl
            with open(pathlib.Path(cfg["journal_dir"]) / "operation.lock", "a") as lock:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                success = operation(cfg, args.action, args.repo)
        print("BACKUP_OPERATION_OK" if success else "BACKUP_OPERATION_FAILED")
        return 0 if success else 1
    except Exception:
        print("INFRASTRUCTURE_NOT_AVAILABLE_OR_OPERATION_FAILED", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
