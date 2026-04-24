# HCM → Recruiting Contract Template Migration

**Date:** 2026-04-24
**Engineer:** s.haustein@bhgdigital.de
**App:** meingedeck (production)
**Package commit (platforms-recruiting):** `57b30e7`

## Context

Contract-template feature was originally built into the HCM module as an early home. The logic has since moved into Recruiting (`rec_contract_templates`, `rec_contracts`, `RecContractTemplate`). This migration copies the existing HCM templates into Recruiting so applicant-facing contracts can be sent directly from the hiring flow, and deactivates the HCM sources so they no longer surface in any new UI. Existing signed `hcm_onboarding_contracts` are untouched — they render from their own `personalized_content` snapshot column and do not depend on the live template.

## Before

| Table | Team 3 rows | Notes |
|---|---|---|
| `hcm_contract_templates` (not soft-deleted) | 2, both `is_active=true` | `#1 Arbeitsvertrag (AV)`, `#2 Infektionsschutzgesetz (IFSG)` |
| `rec_contract_templates` (team 3) | 0 | empty target |
| `hcm_onboarding_contracts` | untouched | continue using `personalized_content` snapshot |

## Commands executed (on Forge, meingedeck)

All commands are idempotent and safe to rerun.

```bash
# Step 2 — copy
php artisan recruiting:copy-hcm-templates --dry-run                   # preview
php artisan recruiting:copy-hcm-templates --dry-run --detail          # field-level verification
php artisan recruiting:copy-hcm-templates                              # result: 2 copied, 0 skipped, 0 errors

# Step 4 — deactivate HCM sources
php artisan recruiting:deactivate-hcm-templates --dry-run             # preview
php artisan recruiting:deactivate-hcm-templates                        # result: 2 deactivated
```

### What the copy did
For each HCM source row (`whereNull('deleted_at')`): inserted a new `rec_contract_templates` row with a fresh UUIDv7, preserving `name`, `code`, `description`, `content`, `field_mappings`, `requires_signature`, `is_active`, `sort_order`, `team_id`, `created_by_user_id`, and original `created_at`. `updated_at = now()` to mark the copy time. Dedup via `(team_id, name)` — rerun is a no-op.

`field_mappings`-Prefix-Rewrite `onboarding.` → `applicant.` is implemented but was a no-op for both source rows (neither template used the `onboarding.*` prefix; they use `contact.*`, `meta.*`, `contract.extra_field.*`).

### What the deactivate did
`UPDATE hcm_contract_templates SET is_active = false, updated_at = NOW() WHERE id IN (...) AND deleted_at IS NULL` — applied only to rows where `is_active = true` (idempotent; rerun skips already-false rows).

## After

| Table | State |
|---|---|
| `hcm_contract_templates` (team 3) | 2 rows, both `is_active=false`, not deleted. FK from `hcm_onboarding_contracts` still resolves. |
| `rec_contract_templates` (team 3) | 2 rows, both `is_active=true`, fresh UUIDs, original `created_at` preserved. |
| `hcm_onboarding_contracts` | unchanged. Continue rendering from `personalized_content`. |

## Rollback

```sql
-- 1. Re-activate HCM sources
UPDATE hcm_contract_templates
   SET is_active = true, updated_at = NOW()
 WHERE deleted_at IS NULL;

-- 2. Remove the copied Recruiting templates (identify by preserved created_at + team + name)
DELETE FROM rec_contract_templates
 WHERE team_id = 3
   AND name IN ('Arbeitsvertrag', 'Infektionsschutzgesetz')
   AND created_at IN ('2026-03-26 07:11:09', '2026-03-26 07:11:20');
```

> **DO NOT run step 2 if `rec_contracts` have already been created from these templates.** The `rec_contracts.rec_contract_template_id` FK has `cascadeOnDelete` — deleting the template would remove live signed/in-progress contracts. Check first:
> ```sql
> SELECT COUNT(*) FROM rec_contracts
>  WHERE rec_contract_template_id IN (
>    SELECT id FROM rec_contract_templates
>     WHERE team_id = 3 AND name IN ('Arbeitsvertrag', 'Infektionsschutzgesetz')
>  );
> ```
> If that returns > 0, only run step 1 (re-activate HCM) and investigate.

## Known follow-up (addressed in Phase A of the Recruiting contract send flow, see below)

The copied `field_mappings` reference `contract.extra_field.*` for `zuschlag`, `stundenlohn`, `vertragsbeginn`, `vertragsende`. When we dug into HCM production, we found **zero** `core_extra_field_definitions` rows for the `hcm_onboarding_contract` context — the HCM "Felder bearbeiten" UI was populated with values despite the definitions not living in the shared-table; the previous storage path is not relevant going forward. For Recruiting we built a cleaner model. See Phase A below.

---

# Recruiting-side contract send flow — Phase A (prerequisites)

**Date:** 2026-04-24
**App:** meingedeck (production)

## Rationale / what changed vs. HCM

Instead of porting 4 extra-field definitions, we restructured how the 4 contract-body values get resolved:

| Value | New location | Why |
|---|---|---|
| `zuschlag` | **Baked into the contract body** across 4 template variants (0,50 / 1,00 / 1,50 / 2,00 €) | Recruiter picks the variant matching the negotiated bonus — fewer moving parts, fewer filled-at-runtime fields |
| `stundenlohn` | Central team-scoped setting `RecApplicantSettings.settings['minimum_wage_hourly']` (default 13.90) | Legally uniform across all employees; changes ~once a year by law; maintained once in the settings modal |
| `vertragsbeginn` | Extra field on `rec_contract` (`date`, required) | Per-contract; recruiter sets it when assigning |
| `vertragsende` | Extra field on `rec_contract` (`date`, required), **auto-computed** from `vertragsbeginn` | Always = (start + 1 year).startOfMonth().subDay(). Example: 15.02.2026 → 31.01.2027. Stored so it is editable if needed. |

## Changes shipped in Phase A

1. **`RecApplicantSettings::DEFAULT_SETTINGS`** — added `minimum_wage_hourly => 13.90`. Existing rows pick up the key automatically via the `array_merge(DEFAULT_SETTINGS, …)` in `ApplicantSettingsModal::openSettings()`.

2. **`RecContractTemplate::resolveSource()`** — added new `settings.*` prefix branch: reads `RecApplicantSettings.settings[$key]` for the applicant's team, formats decimals as German number (e.g. `13,90`), booleans as `ja`/`nein`.

3. **New artisan `recruiting:seed-rec-contract-extra-fields`** — creates `vertragsbeginn` and `vertragsende` as `core_extra_field_definitions` with `context_type='Platform\\Recruiting\\Models\\RecContract'`, `context_id=null`, scoped per team that already has `rec_contract_templates`. Idempotent.

4. **New artisan `recruiting:create-arbeitsvertrag-variants`** — clones the base `Arbeitsvertrag` template (code `AV`) into four variants with `{{zuschlag}}` literal-replaced by `0,50` / `1,00` / `1,50` / `2,00`. Strips `zuschlag` from `field_mappings`, remaps `stundenlohn` to `settings.minimum_wage_hourly`. Deactivates the base template (`is_active=false`) by default. Use `--keep-base` to retain it. Idempotent via `(team_id, code)` dedup.

5. **Settings modal UI** — added an input field for `minimum_wage_hourly` in the "Allgemein" tab of `ApplicantSettingsModal`.

## Commands to run on Forge (in order)

```bash
php artisan recruiting:seed-rec-contract-extra-fields --dry-run
php artisan recruiting:seed-rec-contract-extra-fields

php artisan recruiting:create-arbeitsvertrag-variants --dry-run
php artisan recruiting:create-arbeitsvertrag-variants
```

After running both, the "Infektionsschutzgesetz" template stays as-is; the "Arbeitsvertrag" gets 4 `is_active=true` variants plus the now-deactivated base. The recruiter picks one of the 4 when assigning a contract in Phase B.

## `field_mappings` reference for future templates

The 4 new variants end up with this `field_mappings` shape (inherited from base, minus `zuschlag`, `stundenlohn` remapped):

```json
{
  "datum_heute": "meta.datum_heute",
  "kontakt_ort": "contact.address.city",
  "kontakt_plz": "contact.address.postal_code",
  "stundenlohn": "settings.minimum_wage_hourly",
  "vertragsende": "contract.extra_field.vertragsende",
  "vertragsbeginn": "contract.extra_field.vertragsbeginn",
  "kontakt_strasse": "contact.address.street",
  "kontakt_vorname": "contact.first_name",
  "kontakt_nachname": "contact.last_name",
  "kontakt_geburtsdatum": "contact.birth_date"
}
```

## Rollback

1. Deactivate variants + re-activate base:
```sql
UPDATE rec_contract_templates SET is_active = false, updated_at = NOW()
 WHERE team_id = 3 AND code IN ('AV-050','AV-100','AV-150','AV-200');

UPDATE rec_contract_templates SET is_active = true, updated_at = NOW()
 WHERE team_id = 3 AND code = 'AV' AND deleted_at IS NULL;
```

2. Remove the seeded extra-field definitions (only if no values have been written yet):
```sql
DELETE FROM core_extra_field_definitions
 WHERE team_id = 3
   AND context_type = 'Platform\\Recruiting\\Models\\RecContract'
   AND context_id IS NULL
   AND name IN ('vertragsbeginn','vertragsende');
```

3. Remove the `minimum_wage_hourly` key from settings via tinker or the settings UI (unset it).
