# Dokument-Anzeige in der HR-MA-Ansicht (employees/show)

**Datum:** 2026-07-13
**Status:** Approved (Chat, S. Haustein)

## Problem

Die MA-Ansicht im HR-Backend rendert die 14 Dokument-Spalten (`*_file_id` auf
`rec_employees`) als Dateiname + „Ersetzen"-Button — es gibt keine Möglichkeit,
das Dokument anzusehen. Die Bewerber-Ansicht hat Anzeige über die zentrale
Core-Extra-Fields-Komponente; die MA-Ansicht nutzt aber einen eigenen Renderer
über native Spalten.

## Entscheidung: Klick → neuer Tab (kein Modal)

Verworfene Alternativen:
- **Modal mit `<img>`/`<iframe>`:** zu viel UI-State, PDF-iframe zickig.
- **Signierte Core-URL direkt im Blade:** URL wird beim Rendern erzeugt und
  läuft nach 60 min ab → 403 bei lange offenen Tabs.

Gewählt: **Auth-Route mit Redirect auf frisch signierte Core-URL.**

## Komponenten

1. **`Support/EmployeeFileSlots`** (pure, unit-getestet): Whitelist der 14
   erlaubten Dokument-Spalten. Drift-Schutz-Test: alle File-Spalten aus
   `ApplicantEmployeeFieldMapping` müssen enthalten sein.
2. **`Http/Controllers/EmployeeFileController`** (invokable):
   `GET /recruiting/employees/{employee}/files/{slot}`
   - Slot nicht in Whitelist → 404
   - `RecEmployee` scoped auf `auth()->user()->currentTeam->id` → 404 fremdes Team
   - Spalte leer / `ContextFile` fehlt → 404
   - sonst: Redirect auf `$file->url` (signierte `core.context-files.show`-URL,
     Streaming + `Content-Disposition: inline` bleiben im Core)
3. **Blade `employees/show.blade.php`:** Dateiname wird `<a target="_blank">`
   auf die neue Route (stabile URL, Signatur entsteht erst beim Klick).

## Sicherheit

Session-Auth (gleiche Middleware wie alle HR-Seiten) + Team-Scope + Spalten-
Whitelist. Kein frei wählbarer file_id-Parameter — der Controller liest die
file_id aus der MA-Spalte.

## Nicht-Ziele

- Kein Inline-Preview/Modal.
- Mitarbeiterportal (public) bleibt unverändert — bewusst HR-only.
- Kein Core-Touch.
