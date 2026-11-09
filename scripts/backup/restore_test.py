"""Destructive only inside a fresh directory on an explicitly provisioned isolated runner."""
import datetime
import os
import pathlib
import re
import shlex
import shutil
import tempfile
import time

from backup import repository, run


def safe_path(value):
    if not re.fullmatch(r"/[A-Za-z0-9_./-]+", value):
        raise ValueError("Use absolute paths without shell metacharacters on the isolated runner")
    return pathlib.Path(value).resolve(strict=True)


def restore_test(cfg, repo):
    test = cfg["restore_test"]
    root = safe_path(test["root"])
    # This marker is provisioned only on the isolated recovery host; never on primary.
    if test.get("isolated") is not True or (root / ".euisis-isolated-restore").read_text().strip() != "ISOLATED_RUNNER":
        raise ValueError("Isolated runner not provisioned")
    if root == pathlib.Path("/") or root.is_symlink() or root.stat().st_mode & 0o077:
        raise ValueError("Restore root must be private")
    for key in ("pg_ctl", "php", "application_path", "files_root"):
        safe_path(test[key])
    if repo not in (1, 2):
        raise ValueError("Invalid repository")
    for key in ("database", "database_user"):
        if not re.fullmatch(r"[A-Za-z0-9_]+", test[key]):
            raise ValueError("Invalid database identifier")
    # Exercise PITR, including archive-get, on each repository. Failure to reach the target
    # fails PostgreSQL startup and therefore the test. Never claim continuity from min/max WAL.
    target_epoch = int(time.time()) - int(test.get("target_lag_seconds", 900))
    candidates = [b for b in repository(cfg, repo)["backups"] if b["completed_at"] < target_epoch]
    if not candidates:
        raise ValueError("No backup preceding recovery target")
    target = datetime.datetime.fromtimestamp(target_epoch, datetime.timezone.utc).isoformat()
    sandbox = pathlib.Path(tempfile.mkdtemp(prefix="restore-", dir=root))
    data = sandbox / "data"
    socket = sandbox / "socket"
    socket.mkdir(mode=0o700)
    attempted_start = False
    try:
        run([cfg["pgbackrest"], "--config=" + cfg["pgbackrest_config"], "--stanza=" + cfg["stanza"],
             "--repo=" + str(repo), "--reset-pg1-host", "--pg1-path=" + str(data),
             "--tablespace-map-all=" + str(sandbox / "tablespaces"), "--archive-mode=off",
             "--type=time", "--target=" + target, "--target-action=promote",
             "--set=" + candidates[0]["reference"], "restore"], timeout=int(cfg.get("operation_timeout_seconds", 86400)))
        # Do not boot a copy of production's config, preloads, hooks or connection settings.
        # Replace files (unlink symlinks first), then supply a complete minimal local config.
        for name in ("postgresql.auto.conf", "postgresql.conf", "pg_hba.conf", "pg_ident.conf"):
            path = data / name
            path.unlink(missing_ok=True)
            path.touch(mode=0o600)
        (data / "standby.signal").unlink(missing_ok=True)
        (data / "recovery.signal").touch(mode=0o600)
        archive_get = shlex.join([cfg["pgbackrest"], "--config=" + cfg["pgbackrest_config"],
                                  "--stanza=" + cfg["stanza"], "--repo=" + str(repo), "archive-get", "%f", "%p"])
        quote = lambda value: "'" + str(value).replace("'", "''") + "'"
        settings = {"data_directory": data, "hba_file": data / "pg_hba.conf", "ident_file": data / "pg_ident.conf",
                    "listen_addresses": "", "unix_socket_directories": socket, "unix_socket_permissions": "0700",
                    "port": "55432", "archive_mode": "off", "ssl": "off", "hot_standby": "off",
                    "restore_command": archive_get, "recovery_target_time": target, "recovery_target_action": "promote",
                    "recovery_target_timeline": "latest", "default_transaction_read_only": "on"}
        (data / "postgresql.conf").write_text("\n".join(k + " = " + quote(v) for k, v in settings.items()) + "\n")
        # No TCP listener; socket inside mode-0700 sandbox. No other OS user can authenticate.
        (data / "pg_hba.conf").write_text("local all all trust\n")
        attempted_start = True
        run([test["pg_ctl"], "-D", str(data), "-l", str(sandbox / "postgres.log"), "-w", "-t", "300", "start"], timeout=330)
        run([test["php"], str(pathlib.Path(test["application_path"]) / "scripts/backup/restore_check.php"),
             str(socket), "55432", test["database"], test["database_user"], test["files_root"]], timeout=300)
    finally:
        # Never delete a directory while its PostgreSQL may still be running.
        stopped = not attempted_start
        if attempted_start:
            try:
                run([test["pg_ctl"], "-D", str(data), "-w", "-t", "60", "-m", "immediate", "stop"], timeout=70)
                stopped = True
            except Exception:
                stopped = not (data / "postmaster.pid").exists()
        if stopped and sandbox.resolve().parent == root and sandbox.name.startswith("restore-"):
            shutil.rmtree(sandbox)
        else:
            raise RuntimeError("Cleanup requires operator intervention; sandbox retained")
    return {"backup_reference": candidates[0]["reference"], "backup_size": candidates[0]["size"], "recovery_target": target_epoch}
