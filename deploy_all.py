"""Deploy a complete NexA AI release over authenticated FTPS.

Required environment variables:
  NEXA_FTPS_HOST, NEXA_FTPS_USER, NEXA_FTPS_PASS
Optional:
  NEXA_FTPS_REMOTE_ROOT (default: /nexa-ai)
  NEXA_BASE_LOCAL (default: repository root)

The release set is derived from the canonical frontend plus an explicit API
allowlist. Missing files fail the deployment; nothing is silently skipped.
"""

from __future__ import annotations

import ftplib
import os
from pathlib import Path

BASE_LOCAL = Path(os.environ.get("NEXA_BASE_LOCAL", Path(__file__).resolve().parent)).resolve()
FTPS_HOST = os.environ.get("NEXA_FTPS_HOST", "")
FTPS_USER = os.environ.get("NEXA_FTPS_USER", "")
FTPS_PASS = os.environ.get("NEXA_FTPS_PASS", "")
REMOTE_ROOT = os.environ.get("NEXA_FTPS_REMOTE_ROOT", "/nexa-ai")

API_FILES = [
    "config.php", "security.php", "db.php", "deepseek-fallback.php", "bedrock-fallback.php",
    "app-ask.php", "ask.php", "quiz-api.php", "leaderboard.php", "app-config.php",
    "auth-check.php", "auth-login.php", "auth-register.php", "auth-verify-otp.php",
    "auth-logout.php", "auth-profile.php", "auth-google.php", "auth-forgot-password.php",
    "auth-reset-password.php", "report-problem.php", "sync-history.php",
    "subscription-plans.php", "subscribe.php", "verify-payment.php", "save-fcm-token.php",
    "telegram-webhook.php", "ping.php", "admin-login.php", "admin-check.php",
    "admin-logout.php", "admin-stats.php", "admin-config.php", "admin-limit.php",
    "admin-announcement.php", "admin-subscriptions.php", "admin-clear-cache.php",
    "admin-export-csv.php", "admin-export-telegram-csv.php", "admin-export-usage.php",
    "admin-reports.php", "admin-telegram-broadcast.php", "admin-telegram-users.php",
    "push-broadcast.php",
]

BLOCKED_PARTS = {".env", "fcm-key.json", "gemini_raw_dump.txt", "trace.txt"}


def release_files() -> list[tuple[Path, str]]:
    files: list[tuple[Path, str]] = []
    root_htaccess = BASE_LOCAL / ".htaccess"
    files.append((root_htaccess, ".htaccess"))

    frontend = BASE_LOCAL / "frontend"
    for path in sorted(frontend.rglob("*")):
        if path.is_file() and path.name != "README.md":
            files.append((path, path.relative_to(BASE_LOCAL).as_posix()))

    api_root = BASE_LOCAL / "backend" / "api"
    for name in API_FILES:
        files.append((api_root / name, f"backend/api/{name}"))
    return files


def validate() -> list[tuple[Path, str]]:
    missing = []
    for local, relative in release_files():
        if not local.is_file():
            missing.append(relative)
        if any(part in BLOCKED_PARTS for part in Path(relative).parts):
            raise SystemExit(f"Refusing to deploy blocked file: {relative}")
    if missing:
        raise SystemExit("Required release files are missing: " + ", ".join(missing))
    for secret in ["backend/.env", "backend/api/fcm-key.json", "backend/data/fcm-key.json"]:
        if (BASE_LOCAL / secret).exists():
            raise SystemExit(f"Refusing release while private artifact exists: {secret}")
    return release_files()


def ensure_remote_dir(ftp: ftplib.FTP_TLS, directory: str) -> None:
    current = ftp.pwd()
    try:
        ftp.cwd(directory)
        return
    except ftplib.error_perm:
        pass

    parts = [part for part in directory.strip("/").split("/") if part]
    ftp.cwd("/")
    for part in parts:
        try:
            ftp.cwd(part)
        except ftplib.error_perm:
            ftp.mkd(part)
            ftp.cwd(part)
    ftp.cwd(current)


def upload() -> None:
    files = validate()
    required = {"NEXA_FTPS_HOST": FTPS_HOST, "NEXA_FTPS_USER": FTPS_USER, "NEXA_FTPS_PASS": FTPS_PASS}
    missing = [name for name, value in required.items() if not value]
    if missing:
        raise SystemExit("Missing deployment environment variable(s): " + ", ".join(missing))

    ftp = ftplib.FTP_TLS(timeout=60)
    ftp.connect(FTPS_HOST, 21)
    ftp.login(FTPS_USER, FTPS_PASS)
    ftp.prot_p()
    ensure_remote_dir(ftp, REMOTE_ROOT)
    ftp.cwd(REMOTE_ROOT)

    for local, relative in files:
        remote = relative.replace("\\", "/")
        remote_dir, remote_name = remote.rsplit("/", 1) if "/" in remote else ("", remote)
        if remote_dir:
            ensure_remote_dir(ftp, f"{REMOTE_ROOT.rstrip('/')}/{remote_dir}")
            ftp.cwd(f"{REMOTE_ROOT.rstrip('/')}/{remote_dir}")
        with local.open("rb") as handle:
            ftp.storbinary(f"STOR {remote_name}", handle)
        ftp.cwd(REMOTE_ROOT)
        print(f"uploaded {relative}")

    ftp.quit()
    print(f"Deployment completed: {len(files)} files")


if __name__ == "__main__":
    upload()