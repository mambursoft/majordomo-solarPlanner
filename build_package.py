#!/usr/bin/env python3
"""
Build package script for the MajorDoMo Solar Planner module.

1. (optional) Checks the remote git repository for updates.
2. Validates modinfo.json metadata.
3. Runs php -l on every PHP file (if a php binary is available).
4. Packages the module into solarplanner.tar.gz, solarplanner.tgz,
   app_solarplanner.tar.gz and app_solarplanner.tgz.
5. Reports archive sizes and SHA-256 checksums.

Usage:
    python build_package.py [--force] [--skip-git]
"""

import os
import sys
import json
import hashlib
import tarfile
import argparse
import subprocess
from pathlib import Path

ROOT_DIR = Path(__file__).resolve().parent

# Files/dirs that make up the installable module.
PACKAGE_FILES = [
    "modules",
    "templates",
    "languages",
    "scripts",
    "examples",
    "docs",
    "img",
    "modinfo.json",
    "README.md",
    "README.ru.md",
    "LICENSE",
    "NOTICE.md",
    "CHANGELOG.md",
]

EXCLUDE_DIRS = {".git", ".github", ".idea", ".vscode", "__pycache__",
                ".pytest_cache", ".user_uploaded", "fixtures/real"}
EXCLUDE_SUFFIX = (".pyc", ".tar.gz", ".tgz", ".zip", ".log", ".tmp")
# Never ship private configuration.
EXCLUDE_NAMES = {"config.local.json", "property-map.local.json", "secrets.json", ".env"}


def log(msg, level="INFO"):
    colors = {
        "INFO": "\033[94m[INFO]\033[0m",
        "OK": "\033[92m[OK]\033[0m",
        "WARN": "\033[93m[WARN]\033[0m",
        "ERROR": "\033[91m[ERROR]\033[0m",
    }
    print(f"{colors.get(level, f'[{level}]')} {msg}")


def check_git_updates(force=False):
    log("Checking remote git repository for updates...")
    try:
        subprocess.run(["git", "rev-parse", "--is-inside-work-tree"],
                       check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, cwd=ROOT_DIR)
    except Exception:
        log("Not a git repository; skipping remote update check.", "WARN")
        return True
    try:
        fetch = subprocess.run(["git", "fetch", "origin"], stdout=subprocess.PIPE,
                               stderr=subprocess.PIPE, text=True, cwd=ROOT_DIR, timeout=20)
        if fetch.returncode != 0:
            log(f"git fetch unavailable: {fetch.stderr.strip()}", "WARN")
            return True
        branch = subprocess.run(["git", "rev-parse", "--abbrev-ref", "HEAD"],
                                stdout=subprocess.PIPE, text=True, cwd=ROOT_DIR).stdout.strip() or "main"
        diff = subprocess.run(["git", "rev-list", "--left-right", "--count", f"HEAD...origin/{branch}"],
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, cwd=ROOT_DIR)
        if diff.returncode == 0:
            parts = diff.stdout.strip().split()
            if len(parts) == 2:
                ahead, behind = int(parts[0]), int(parts[1])
                if behind > 0 and not force:
                    log(f"Local is BEHIND origin/{branch} by {behind}. Run git pull or use --force.", "ERROR")
                    return False
                log(f"Local ahead {ahead}, behind {behind} of origin/{branch}.", "OK")
    except Exception as e:
        log(f"git status check error: {e}", "WARN")
    return True


def check_metadata():
    log("Validating modinfo.json...")
    p = ROOT_DIR / "modinfo.json"
    if not p.exists():
        log("modinfo.json not found!", "ERROR")
        return False
    try:
        data = json.loads(p.read_text(encoding="utf-8"))
        for req in ("name", "version", "title"):
            if req not in data:
                log(f"modinfo.json missing key: {req}", "ERROR")
                return False
        log(f"modinfo.json OK: {data['name']} v{data['version']} ({data['title']})", "OK")
    except Exception as e:
        log(f"modinfo.json invalid: {e}", "ERROR")
        return False
    return True


def check_php_syntax():
    log("Checking PHP syntax (php -l)...")
    php_files = [p for p in ROOT_DIR.glob("**/*.php") if ".git" not in p.parts]
    if not php_files:
        log("No PHP files found.", "WARN")
        return True
    try:
        subprocess.run(["php", "-v"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True)
    except Exception:
        log("php CLI not found; skipping php -l.", "WARN")
        return True
    errors = 0
    for p in php_files:
        res = subprocess.run(["php", "-l", str(p)], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        if res.returncode != 0:
            log(f"Syntax error in {p.relative_to(ROOT_DIR)}:\n{res.stdout.strip()}", "ERROR")
            errors += 1
    if errors:
        log(f"PHP syntax FAILED: {errors} error(s).", "ERROR")
        return False
    log(f"PHP syntax OK ({len(php_files)} files).", "OK")
    return True


def should_exclude(rel_path):
    parts = Path(rel_path).parts
    for p in parts:
        if p in {".git", ".github", ".idea", ".vscode", "__pycache__", ".pytest_cache", ".user_uploaded"}:
            return True
    if "fixtures" in parts and "real" in parts:
        return True
    name = os.path.basename(rel_path)
    if name in EXCLUDE_NAMES:
        return True
    if name.endswith(EXCLUDE_SUFFIX):
        return True
    return False


def build_tar(archive_name):
    target = ROOT_DIR / archive_name
    if target.exists():
        target.unlink()
    log(f"Creating {archive_name}...")
    count = 0
    with tarfile.open(target, "w:gz") as tar:
        for item in PACKAGE_FILES:
            ip = ROOT_DIR / item
            if not ip.exists():
                continue
            if ip.is_file():
                rel = ip.relative_to(ROOT_DIR)
                if not should_exclude(str(rel)):
                    tar.add(ip, arcname=str(rel).replace("\\", "/"))
                    count += 1
            else:
                for root, _, files in os.walk(ip):
                    for f in files:
                        full = Path(root) / f
                        rel = full.relative_to(ROOT_DIR)
                        if not should_exclude(str(rel)):
                            tar.add(full, arcname=str(rel).replace("\\", "/"))
                            count += 1
    size_kb = target.stat().st_size / 1024.0
    sha = hashlib.sha256(target.read_bytes()).hexdigest()
    log(f"{archive_name}: {size_kb:.1f} KB, {count} files, SHA256 {sha[:16]}...", "OK")
    return {"name": archive_name, "size_kb": size_kb, "files": count, "sha256": sha}


def main():
    ap = argparse.ArgumentParser(description="Build Solar Planner module package.")
    ap.add_argument("--force", action="store_true")
    ap.add_argument("--skip-git", action="store_true")
    args = ap.parse_args()

    print("=" * 52)
    print("  MajorDoMo Solar Planner - Package Builder")
    print("=" * 52)

    if not args.skip_git and not check_git_updates(force=args.force):
        sys.exit(1)
    if not check_metadata():
        sys.exit(1)
    if not check_php_syntax():
        sys.exit(1)

    results = [build_tar(t) for t in (
        "solarplanner.tar.gz", "solarplanner.tgz",
        "app_solarplanner.tar.gz", "app_solarplanner.tgz",
    )]

    print("\n" + "=" * 52)
    print("  BUILD SUMMARY")
    print("=" * 52)
    for r in results:
        print(f"  * {r['name']:<24} {r['size_kb']:>7.1f} KB  ({r['files']} files)  SHA {r['sha256'][:20]}...")
    print("=" * 52)
    log("All packages built.", "OK")


if __name__ == "__main__":
    main()
