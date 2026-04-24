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

## Known follow-up (out of scope for this migration)

The copied `field_mappings` reference `contract.extra_field.*` for `zuschlag`, `stundenlohn`, `vertragsbeginn`, `vertragsende`. These extra-field **definitions** currently exist on the `hcm_onboarding_contract` morph context. For new contracts created from these templates in Recruiting, the placeholders will render empty until equivalent definitions are created on the `rec_contract` context (`core_extra_field_definitions.context_type = 'rec_contract'`). Existing signed HCM contracts are unaffected (they use the snapshot).
