#!/usr/bin/env python3
"""
deploy-lock.py — Modul deployen, ohne `composer update` zu brauchen.

Hintergrund
-----------
`composer update` in der Host-App (demo.bhgdigital.de) laedt die Metadaten *aller*
VCS-Repositories aus deren composer.json. Seit `martin3r/platforms-avatar` auf GitHub
nicht mehr erreichbar ist ("Repository not found"), bricht damit jeder Update-Lauf ab —
auch ein partielles `composer update <nur-dieses-paket>`. Deploys sind dadurch blockiert.

`composer install` liest dagegen ausschliesslich die composer.lock und fragt kein
VCS-Repository ab. Es genuegt also, in der Lock-Datei den Commit-Zeiger dieses einen
Pakets weiterzudrehen und die Lock zu pushen — genau das, was der Deploy braucht.

Was das Skript macht
--------------------
1. Ziel-Commit im Modul-Repo aufloesen (Default: origin/main).
2. **Drift-Check**: die composer.json des Ziel-Commits gegen den Lock-Eintrag pruefen.
   Weichen lock-relevante Metadaten ab (require, autoload, extra.laravel.providers, ...),
   reicht ein Zeiger-Update NICHT — dann Abbruch mit Hinweis.
3. Host-App auf sauberen main bringen (checkout + pull --ff-only).
4. Im Lock-Eintrag chirurgisch ersetzen: source.reference, dist.url, dist.reference, time.
   Sonst bleibt die Datei Byte fuer Byte gleich (Diff = 4 Zeilen).
5. Optional lokal `composer install` (--install), dann committen und pushen.

Aufruf
------
    python tools/deploy-lock.py                      # origin/main deployen
    python tools/deploy-lock.py --ref HEAD           # anderen Commit deployen
    python tools/deploy-lock.py --dry-run            # nur pruefen/anzeigen
    python tools/deploy-lock.py --no-push            # patchen + committen, nicht pushen
    python tools/deploy-lock.py --install            # zusaetzlich lokales vendor/ syncen

Fremdes Modul ohne lokalen Klon (Commit + composer.json kommen per GitHub-API ueber die
`gh`-CLI, es wird nichts geklont):

    python tools/deploy-lock.py --package martin3r/platform-integrations                                 --github martin3r-me/platforms-integrations

Hinweis zu --install: die lokale Host-App kann `composer install` derzeit nicht ausfuehren
(PHP 8.2 statt 8.3, ext-gd fehlt). Der Schalter ist fuer Umgebungen gedacht, in denen das geht;
fuer den Deploy ist er nicht noetig — dort installiert der Server.
"""

from __future__ import annotations

import argparse
import base64
import json
import re
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

DEFAULT_APP = Path("C:/Coding/Platforms/demo.bhgdigital.de")
DEFAULT_PACKAGE = "martin3r/platform-asset-manager"
REPO_ROOT = Path(__file__).resolve().parent.parent

# Felder, die composer aus der composer.json des Pakets in die Lock uebernimmt.
# Weicht eines davon ab, ist der Lock-Eintrag inhaltlich veraltet und ein reines
# Zeiger-Update wuerde falsche Metadaten (z. B. fehlende ServiceProvider) einfrieren.
MIRRORED_FIELDS = (
    "type", "require", "require-dev", "conflict", "replace", "provide", "suggest",
    "autoload", "autoload-dev", "extra", "bin", "include-path", "target-dir",
    "description", "keywords", "homepage", "license", "authors", "support",
)


def die(msg: str) -> None:
    print(f"\n[FEHLER] {msg}", file=sys.stderr)
    sys.exit(1)


def run(cmd: list[str], cwd: Path, check: bool = True) -> str:
    res = subprocess.run(cmd, cwd=cwd, capture_output=True, text=True,
                         encoding="utf-8", errors="replace")
    if check and res.returncode != 0:
        die(f"Befehl fehlgeschlagen: {' '.join(cmd)}\n{res.stdout}{res.stderr}")
    return (res.stdout or "").strip()


def gh_api(path: str, jq: str | None = None) -> str:
    """GitHub-API über die `gh`-CLI. Nutzt deren Anmeldung — kein eigener Token nötig."""
    cmd = ["gh", "api", path]
    if jq:
        cmd += ["--jq", jq]
    res = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8", errors="replace")
    if res.returncode != 0:
        die(f"GitHub-API fehlgeschlagen: {' '.join(cmd)}\n{res.stdout}{res.stderr}")
    return (res.stdout or "").strip()


def target_from_github(slug: str, ref: str) -> tuple[str, str, str, dict]:
    """
    Ziel-Commit und composer.json direkt von GitHub holen — ohne das Repo zu klonen.

    Gedacht für **fremde** Module: das eigene liegt hier ohnehin, ein Nachbarmodul (integrations,
    core, ui) aber nicht. Ohne diesen Weg müsste man es erst klonen, nur um zwei Angaben daraus zu
    lesen — Commit-Zeiger und composer.json für den Drift-Check.
    """
    branch = ref.split("/")[-1] if "/" in ref else ref
    meta = json.loads(gh_api(f"repos/{slug}/commits/{branch}"))

    sha = meta["sha"]
    subject = (meta["commit"]["message"] or "").splitlines()[0]
    committed = meta["commit"]["committer"]["date"]

    raw = gh_api(f"repos/{slug}/contents/composer.json?ref={sha}", ".content")
    module_json = json.loads(base64.b64decode(raw).decode("utf-8"))

    return sha, subject, committed, module_json


def find_block(text: str, package: str) -> tuple[int, int]:
    """Grenzen des JSON-Objekts des Pakets im Lock-Text (Zeichen-Indizes)."""
    needle = f'"name": "{package}",'
    pos = text.find(needle)
    if pos < 0:
        die(f"Paket {package} nicht in der composer.lock gefunden.")
    start = text.rfind("\n        {\n", 0, pos)
    if start < 0:
        die("Blockanfang des Pakets nicht gefunden (unerwartetes Lock-Format).")
    start += 1
    end = text.find("\n        }", pos)
    if end < 0:
        die("Blockende des Pakets nicht gefunden (unerwartetes Lock-Format).")
    end = text.find("\n", end + 1) + 1
    return start, end


def check_drift(module_json: dict, lock_entry: dict) -> list[str]:
    problems = []
    for field in MIRRORED_FIELDS:
        want = module_json.get(field)
        have = lock_entry.get(field)
        if field == "license" and isinstance(want, str):
            want = [want]  # composer normalisiert einen String zu einer Liste
        if field == "support":
            # composer ergaenzt support.source/issues selbst -> nur pruefen, was das Modul setzt
            want = want or {}
            if not want:
                continue
            have = {k: v for k, v in (have or {}).items() if k in want}
        if want in (None, {}, []) and have in (None, {}, []):
            continue
        if want != have:
            problems.append(
                f"  - {field}:\n"
                f"      composer.json: {json.dumps(want, ensure_ascii=False)}\n"
                f"      composer.lock: {json.dumps(have, ensure_ascii=False)}"
            )
    return problems


def main() -> None:
    ap = argparse.ArgumentParser(
        description="Modul-Deploy per composer.lock-Zeiger (umgeht das blockierte composer update).")
    ap.add_argument("--app", type=Path, default=DEFAULT_APP, help="Pfad zur Host-App")
    ap.add_argument("--repo", type=Path, default=REPO_ROOT, help="Pfad zum Modul-Repo")
    ap.add_argument("--package", default=DEFAULT_PACKAGE, help=f"Composer-Paketname (Default: {DEFAULT_PACKAGE})")
    ap.add_argument("--github", default=None, metavar="owner/repo",
                    help="Commit und composer.json per GitHub-API holen statt aus --repo. "
                         "Fuer Nachbarmodule, die hier nicht ausgecheckt sind (z. B. martin3r-me/platforms-integrations).")
    ap.add_argument("--ref", default="origin/main", help="zu deployender Commit/Ref im Modul-Repo")
    ap.add_argument("--message", default=None, help="Commit-Message in der Host-App")
    ap.add_argument("--no-fetch", action="store_true", help="kein git fetch im Modul-Repo")
    ap.add_argument("--no-push", action="store_true", help="committen, aber nicht pushen")
    ap.add_argument("--install", action="store_true", help="nach dem Patch lokal `composer install` laufen lassen")
    ap.add_argument("--dry-run", action="store_true", help="nur anzeigen, nichts aendern")
    args = ap.parse_args()

    app = args.app.resolve()
    lock_path = app / "composer.lock"
    if not lock_path.is_file():
        die(f"composer.lock nicht gefunden: {lock_path}")

    # --- 1. Ziel-Commit + composer.json -----------------------------------
    if args.github:
        print(f"-> GitHub: {args.github} ({args.ref})")
        sha, subject, committed, module_json = target_from_github(args.github, args.ref)
    else:
        repo = args.repo.resolve()
        if not (repo / ".git").exists():
            die(f"{repo} ist kein Git-Repo. Fuer ein Modul ohne lokalen Klon: --github owner/repo")
        if not args.no_fetch:
            print("-> git fetch (Modul-Repo)")
            run(["git", "fetch", "origin", "--quiet"], repo)
        sha = run(["git", "rev-parse", args.ref], repo)
        subject = run(["git", "log", "-1", "--format=%s", sha], repo)
        committed = run(["git", "log", "-1", "--format=%cI", sha], repo)
        module_json = json.loads(run(["git", "show", f"{sha}:composer.json"], repo))

    stamp = datetime.fromisoformat(committed.replace("Z", "+00:00")) \
        .astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S+00:00")
    print(f"-> Ziel: {sha[:7]}  {subject}")
    print(f"         Zeitstempel {stamp}")

    # --- 2. Drift-Check ---------------------------------------------------
    lock_data = json.loads(lock_path.read_text(encoding="utf-8"))
    entry = next((p for p in lock_data.get("packages", []) + lock_data.get("packages-dev", [])
                  if p["name"] == args.package), None)
    if entry is None:
        die(f"{args.package} steht nicht in {lock_path}.")

    problems = check_drift(module_json, entry)
    if problems:
        print("\n[STOP] Die Paket-Metadaten haben sich geaendert — ein Lock-Zeiger-Update genuegt nicht:")
        print("\n".join(problems))
        print("\n  Hier braucht es ein echtes `composer update`. Solange platforms-avatar auf GitHub")
        print("  nicht erreichbar ist, geht das nicht -> mit Martin/Christian klaeren.")
        sys.exit(2)
    print("-> Drift-Check ok (require/autoload/extra/... unveraendert)")

    old_ref = entry["source"]["reference"]
    if old_ref == sha:
        print(f"\nOK: composer.lock zeigt bereits auf {sha[:7]} — nichts zu tun.")
        return

    # --- 3. Host-App vorbereiten ------------------------------------------
    dirty = run(["git", "status", "--porcelain", "--", ".", ":(exclude)composer.lock"], app)
    if dirty:
        die(f"Host-App hat Aenderungen ausser composer.lock:\n{dirty}\n  Bitte committen oder aufraeumen.")
    if args.dry_run:
        print(f"\n[dry-run] wuerde {old_ref[:7]} -> {sha[:7]} in {lock_path} setzen"
              f"{'' if args.no_push else ' und pushen'}.")
        return
    print("-> Host-App: checkout main + pull --ff-only")
    run(["git", "checkout", "main"], app)
    if run(["git", "diff", "--name-only", "--", "composer.lock"], app):
        run(["git", "checkout", "--", "composer.lock"], app)
    run(["git", "pull", "--ff-only", "origin", "main"], app)

    # --- 4. Lock chirurgisch patchen --------------------------------------
    lock_raw = lock_path.read_text(encoding="utf-8")
    start, end = find_block(lock_raw, args.package)
    block = lock_raw[start:end]
    if old_ref not in block:
        # nach dem Pull kann die Lock schon weitergedreht sein
        m = re.search(r'"reference": "([0-9a-f]{40})"', block)
        if not m:
            die("Keine Commit-Referenz im Lock-Block gefunden.")
        old_ref = m.group(1)
        if old_ref == sha:
            print(f"\nOK: composer.lock zeigt nach dem Pull bereits auf {sha[:7]} — nichts zu tun.")
            return
    patched = block.replace(old_ref, sha)
    patched, n = re.subn(r'("time": ")[^"]+(")', rf'\g<1>{stamp}\g<2>', patched)
    if n != 1:
        die(f"Erwartet genau ein 'time'-Feld im Lock-Block, gefunden: {n}")
    lock_path.write_text(lock_raw[:start] + patched + lock_raw[end:], encoding="utf-8", newline="")
    json.loads(lock_path.read_text(encoding="utf-8"))  # Sanity: Lock ist noch valides JSON
    print(f"-> composer.lock gepatcht: {old_ref[:7]} -> {sha[:7]}")
    print(run(["git", "--no-pager", "diff", "--stat", "--", "composer.lock"], app))

    # --- 5. Installieren, committen, pushen -------------------------------
    if args.install:
        print("-> composer install (lokal)")
        res = subprocess.run(["composer", "install", "--no-interaction"], cwd=app,
                             capture_output=True, text=True, encoding="utf-8", errors="replace")
        print((res.stdout or "") + (res.stderr or ""))
        if res.returncode != 0:
            die("composer install fehlgeschlagen — Lock-Patch bleibt liegen, bitte pruefen.")

    message = args.message or f"chore: {args.package.split('/')[-1]} auf {sha[:7]}\n\n{subject}"
    run(["git", "add", "--", "composer.lock"], app)
    run(["git", "commit", "-m", message], app)
    print(f"-> committet: {run(['git', 'rev-parse', '--short', 'HEAD'], app)}")
    if args.no_push:
        print("-> --no-push: Push uebersprungen.")
        return
    print("-> git push origin main")
    res = subprocess.run(["git", "push", "origin", "main"], cwd=app,
                         capture_output=True, text=True, encoding="utf-8", errors="replace")
    print((res.stdout or "") + (res.stderr or ""))
    if res.returncode != 0:
        die("Push abgelehnt — origin/main ist weitergelaufen. Skript einfach erneut starten.")
    print(f"\nOK: Fertig. {args.package} zeigt auf {sha[:7]} ({subject}).")


if __name__ == "__main__":
    main()
