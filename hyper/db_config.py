"""
Settings shared by every hyper/*_sync.py script: database + Tableau login.

Secrets are NOT written in code — they are read from transferpicklist/.env
(git-ignored; see .env.example for the keys). A real environment variable with
the same name overrides the .env value.

Picks the database automatically from where the script lives:
  - inside a Laragon folder (C:\\laragon\\...)  -> LOCAL  (Laragon MySQL, port 3306)
  - anywhere else (e.g. C:\\xampp\\htdocs\\...) -> PRODUCTION (DB_PROD_* from .env)

Override by setting the environment variable TPL_DB_ENV=local or
TPL_DB_ENV=production before running a script.
"""

import os
from pathlib import Path

ENV_FILE = Path(__file__).resolve().parent.parent / ".env"


def _load_env(path):
    """Minimal KEY=VALUE reader (blank lines and # comments ignored, optional quotes)."""
    values = {}
    if path.is_file():
        for line in path.read_text(encoding="utf-8-sig").splitlines():
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, val = line.split("=", 1)
            key, val = key.strip(), val.strip()
            if len(val) >= 2 and val[0] == val[-1] and val[0] in ("'", '"'):
                val = val[1:-1]
            values[key] = val
    return values


_ENV = _load_env(ENV_FILE)


def env(key, default=""):
    val = os.environ.get(key)
    if val is None or val == "":
        val = _ENV.get(key, "")
    return val if val != "" else default


def _require(key):
    val = env(key)
    if val == "":
        raise RuntimeError(f"Missing {key} in {ENV_FILE} — copy .env.example to .env and fill it in.")
    return val


# ── Tableau login (all syncs) ───────────────────────────────────────────
TABLEAU_API_USER = _require("TABLEAU_API_USER")
TABLEAU_API_PASS = _require("TABLEAU_API_PASS")

# ── Database ────────────────────────────────────────────────────────────
_override = os.environ.get("TPL_DB_ENV", "").strip().lower()
if _override in ("local", "production"):
    DB_ENV = _override
else:
    DB_ENV = "local" if "laragon" in str(Path(__file__).resolve()).lower() else "production"

if DB_ENV == "local":
    DB_HOST = env("DB_LOCAL_HOST", "localhost")
    DB_PORT = int(env("DB_LOCAL_PORT", "3306"))
    DB_USER = env("DB_LOCAL_USER", "root")
    DB_PASS = env("DB_LOCAL_PASS", "")
    DB_NAME = env("DB_LOCAL_NAME", "odb")
else:
    DB_HOST = env("DB_PROD_HOST", "localhost")
    DB_PORT = int(env("DB_PROD_PORT", "2215"))
    DB_USER = env("DB_PROD_USER", "root")
    DB_PASS = _require("DB_PROD_PASS")
    DB_NAME = env("DB_PROD_NAME", "odb")
