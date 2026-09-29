-- ============================================================================
-- VORFLUG FUER DAS MITARBEITERKONTO (Canvas 68, Eintrag 1745)
--
-- Canvas 68 verlangt VOR Gate D drei Zahlen:
--   1. Nummer fehlt
--   2. Nummer mehrfach vergeben
--   3. Akte und Kontakt weichen ab
--
-- Grund, woertlich aus dem Canvas:
--   "Die Nummer in der Personalakte wird der Benutzername, der WhatsApp-Versand
--    nutzt heute die Nummer am Kontakt. Beide muessen uebereinstimmen, sonst
--    erhaelt der Mitarbeiter seinen Einmalcode auf einer anderen Nummer als der,
--    mit der er sich anmeldet."
--
-- ALLE ABFRAGEN HIER SIND LESEND. Kein INSERT, kein UPDATE, kein DELETE.
-- Sie veraendern nichts und setzen insbesondere KEINEN ZAS-Export-Marker.
--
-- Voraussetzung: MySQL 8 (wegen REGEXP_REPLACE).
--
-- Die Abfragen bilden nach, was der Code tut:
--   Platform\Recruiting\Support\PhoneE164::suffix()      -- letzte NEUN Ziffern
--   Platform\Recruiting\Support\SharedPhonePlanner       -- wer ist derselbe Mensch
--   Platform\Recruiting\Services\Zas\ContactPhoneSync    -- Akte gegen Kontakt
--
-- Weicht eine Zahl spaeter vom Kommando ab, hat sich der Code geaendert, nicht
-- die Abfrage -- dann gilt das Kommando, nicht diese Datei.
--
-- Teamfilter: die Zeilen mit "AND e.team_id = ?" sind auskommentiert. Ohne sie
-- zaehlt die Abfrage ueber alle Mandanten. Fuer die Vorflug-Zahlen ist das
-- richtig, solange Recruiting nur einen produktiven Mandanten hat.
-- ============================================================================


-- ############################################################################
-- ABFRAGE 0 -- DIE UEBERSICHT. Wenn du nur EINE Sache laufen laesst, diese.
--
-- Liefert alle drei Vorflug-Zahlen in EINER Zeile. Die Einzelabfragen weiter
-- unten brauchst du nur fuer die Faelle, deren Zahl nicht null ist.
-- ############################################################################
WITH basis AS (
    SELECT
        e.id,
        NULLIF(TRIM(COALESCE(e.person_key, '')), '')    AS marker,
        DATE(e.birth_date)                              AS geburtstag,
        RIGHT(REGEXP_REPLACE(e.phone, '[^0-9]', ''), 9) AS suffix
    FROM rec_employees e
    WHERE e.is_active = 1
      AND LENGTH(REGEXP_REPLACE(COALESCE(e.phone, ''), '[^0-9]', '')) >= 9
),
gruppen AS (
    SELECT
        suffix,
        (COUNT(marker) = COUNT(*) AND COUNT(DISTINCT marker) = 1)         AS marker_einig,
        (COUNT(geburtstag) = COUNT(*) AND COUNT(DISTINCT geburtstag) = 1) AS geburt_einig
    FROM basis
    GROUP BY suffix
    HAVING COUNT(*) > 1
)
SELECT
    (SELECT COUNT(*) FROM rec_employees WHERE is_active = 1)
        AS aktive_mitarbeiter,
    (SELECT COUNT(*) FROM rec_employees
      WHERE is_active = 1 AND TRIM(COALESCE(phone, '')) = '')
        AS ohne_nummer,
    (SELECT COUNT(*) FROM rec_employees
      WHERE is_active = 1 AND TRIM(COALESCE(phone, '')) <> ''
        AND LENGTH(REGEXP_REPLACE(phone, '[^0-9]', '')) < 9)
        AS nummer_zu_kurz,
    (SELECT COALESCE(SUM(CASE WHEN marker_einig OR geburt_einig THEN 1 ELSE 0 END), 0) FROM gruppen)
        AS nummer_geteilt_dieselbe_person,
    (SELECT COALESCE(SUM(CASE WHEN marker_einig OR geburt_einig THEN 0 ELSE 1 END), 0) FROM gruppen)
        AS nummer_geteilt_VERSCHIEDENE_MENSCHEN,
    (SELECT COUNT(*) FROM rec_employees e
      WHERE e.is_active = 1
        AND LENGTH(REGEXP_REPLACE(COALESCE(e.phone, ''), '[^0-9]', '')) >= 9
        AND EXISTS (
            SELECT 1 FROM crm_contact_links l
            JOIN crm_phone_numbers p ON p.phoneable_id = l.contact_id
             AND p.is_active = 1 AND p.international IS NOT NULL
             AND (p.phoneable_type = 'crm_contact' OR p.phoneable_type LIKE '%CrmContact')
            WHERE l.linkable_id = e.id AND l.linkable_type LIKE '%RecEmployee')
        AND NOT EXISTS (
            SELECT 1 FROM crm_contact_links l
            JOIN crm_phone_numbers p ON p.phoneable_id = l.contact_id
             AND p.is_active = 1 AND p.international IS NOT NULL
             AND (p.phoneable_type = 'crm_contact' OR p.phoneable_type LIKE '%CrmContact')
            WHERE l.linkable_id = e.id AND l.linkable_type LIKE '%RecEmployee'
              AND RIGHT(REGEXP_REPLACE(p.international, '[^0-9]', ''), 9)
                = RIGHT(REGEXP_REPLACE(e.phone, '[^0-9]', ''), 9)))
        AS akte_gegen_kontakt_abweichend
;


-- ----------------------------------------------------------------------------
-- 1. NUMMER FEHLT
--
-- Wer keine brauchbare Nummer hat, bekommt spaeter keinen Benutzernamen und
-- damit kein Konto. "Brauchbar" heisst: mindestens neun Ziffern -- genau die
-- Schwelle, ab der PhoneE164::suffix() einen Wert liefert. Eine Nummer wie
-- "0170" ist damit so gut wie keine.
-- ----------------------------------------------------------------------------
SELECT
    COUNT(*)                                                            AS aktive_gesamt,
    SUM(CASE WHEN TRIM(COALESCE(e.phone, '')) = '' THEN 1 ELSE 0 END)   AS ohne_nummer,
    SUM(CASE WHEN TRIM(COALESCE(e.phone, '')) <> ''
              AND LENGTH(REGEXP_REPLACE(e.phone, '[^0-9]', '')) < 9
             THEN 1 ELSE 0 END)                                         AS nummer_zu_kurz
FROM rec_employees e
WHERE e.is_active = 1
  -- AND e.team_id = ?
;


-- ----------------------------------------------------------------------------
-- 2. NUMMER MEHRFACH VERGEBEN
--
-- Die Regel stammt aus Canvas 68, Eintrag 1742 und steckt im Code in
-- SharedPhonePlanner. Innerhalb einer Gruppe mit DERSELBEN Nummer gilt:
--
--   alle tragen denselben nicht-leeren Personen-Marker   -> dieselbe Person
--   ODER alle tragen dasselbe nicht-leere Geburtsdatum   -> dieselbe Person
--   sonst                                                -> VERSCHIEDENE Menschen
--
-- Ein fehlendes Geburtsdatum oder ein leerer Marker bestaetigt NIE eine
-- Gleichheit -- im Zweifel Zweifelsfall. Das ist bewusst so: eine falsche
-- Zusammenlegung heisst fremde Ausweiskopie und fremde Bankverbindung auf
-- einem Konto.
--
-- "Dieselbe Nummer" = letzte neun Ziffern, damit +49 152 ..., 0152 ... und
-- 0049152 ... zusammenfallen.
-- ----------------------------------------------------------------------------
WITH basis AS (
    SELECT
        e.id,
        NULLIF(TRIM(COALESCE(e.person_key, '')), '')                    AS marker,
        DATE(e.birth_date)                                              AS geburtstag,
        RIGHT(REGEXP_REPLACE(e.phone, '[^0-9]', ''), 9)                 AS suffix
    FROM rec_employees e
    WHERE e.is_active = 1
      AND LENGTH(REGEXP_REPLACE(COALESCE(e.phone, ''), '[^0-9]', '')) >= 9
      -- AND e.team_id = ?
),
gruppen AS (
    SELECT
        suffix,
        COUNT(*)                                                        AS datensaetze,
        -- alle tragen denselben nicht-leeren Marker?
        (COUNT(marker) = COUNT(*) AND COUNT(DISTINCT marker) = 1)       AS marker_einig,
        -- alle tragen dasselbe nicht-leere Geburtsdatum?
        (COUNT(geburtstag) = COUNT(*) AND COUNT(DISTINCT geburtstag) = 1) AS geburt_einig
    FROM basis
    GROUP BY suffix
    HAVING COUNT(*) > 1
)
SELECT
    SUM(CASE WHEN marker_einig OR geburt_einig THEN 1 ELSE 0 END)       AS gruppen_dieselbe_person,
    SUM(CASE WHEN marker_einig OR geburt_einig THEN datensaetze ELSE 0 END) AS davon_datensaetze,
    SUM(CASE WHEN marker_einig OR geburt_einig THEN 0 ELSE 1 END)       AS gruppen_verschiedene_menschen,
    SUM(CASE WHEN marker_einig OR geburt_einig THEN 0 ELSE datensaetze END) AS davon_datensaetze_hr
FROM gruppen
;


-- ----------------------------------------------------------------------------
-- 2b. DIE FAELLE AUS 2, EINZELN -- fuer die HR-Liste
--
-- Nur Kennungen und die Nummer, KEINE Namen. Wer die Namen braucht, holt sie
-- ueber die Kennung in der Oberflaeche -- so landen sie nicht in einer Datei,
-- die durchs Haus wandert.
-- ----------------------------------------------------------------------------
WITH basis AS (
    SELECT
        e.id,
        NULLIF(TRIM(COALESCE(e.person_key, '')), '')                    AS marker,
        DATE(e.birth_date)                                              AS geburtstag,
        RIGHT(REGEXP_REPLACE(e.phone, '[^0-9]', ''), 9)                 AS suffix
    FROM rec_employees e
    WHERE e.is_active = 1
      AND LENGTH(REGEXP_REPLACE(COALESCE(e.phone, ''), '[^0-9]', '')) >= 9
      -- AND e.team_id = ?
),
gruppen AS (
    SELECT
        suffix,
        (COUNT(marker) = COUNT(*) AND COUNT(DISTINCT marker) = 1)         AS marker_einig,
        (COUNT(geburtstag) = COUNT(*) AND COUNT(DISTINCT geburtstag) = 1) AS geburt_einig
    FROM basis
    GROUP BY suffix
    HAVING COUNT(*) > 1
)
SELECT
    g.suffix                                                            AS nummer_endet_auf,
    GROUP_CONCAT(b.id ORDER BY b.id)                                    AS mitarbeiter_kennungen,
    COUNT(*)                                                            AS datensaetze
FROM gruppen g
JOIN basis b ON b.suffix = g.suffix
WHERE NOT (g.marker_einig OR g.geburt_einig)
GROUP BY g.suffix
ORDER BY COUNT(*) DESC, g.suffix
;


-- ----------------------------------------------------------------------------
-- 3. AKTE UND KONTAKT WEICHEN AB
--
-- Das ist die gefaehrlichste der drei Zahlen, weil sie niemandem auffaellt:
-- Die Anmeldung wird gegen rec_employees.phone geprueft, der Einmalcode geht
-- an die Nummer am CRM-Kontakt. Weichen sie ab, sperrt sich der Mitarbeiter
-- aus, ohne dass jemand etwas falsch gemacht hat.
--
-- Verglichen wird formatunabhaengig ueber die letzten neun Ziffern -- dieselbe
-- Regel wie in ContactPhoneSync. Ein Kontakt kann mehrere aktive Nummern
-- haben; als "abweichend" gilt, wenn KEINE davon zur Akte passt.
-- ----------------------------------------------------------------------------
SELECT
    COUNT(*)                                                            AS mit_kontakt_und_nummer_in_der_akte,
    SUM(CASE WHEN EXISTS (
            SELECT 1
            FROM crm_contact_links l
            JOIN crm_phone_numbers p
              ON p.phoneable_id = l.contact_id
             AND p.is_active = 1
             AND p.international IS NOT NULL
             AND (p.phoneable_type = 'crm_contact' OR p.phoneable_type LIKE '%CrmContact')
            WHERE l.linkable_id = e.id
              AND l.linkable_type LIKE '%RecEmployee'
              AND RIGHT(REGEXP_REPLACE(p.international, '[^0-9]', ''), 9)
                = RIGHT(REGEXP_REPLACE(e.phone, '[^0-9]', ''), 9)
        ) THEN 0 ELSE 1 END)                                            AS abweichend
FROM rec_employees e
WHERE e.is_active = 1
  AND LENGTH(REGEXP_REPLACE(COALESCE(e.phone, ''), '[^0-9]', '')) >= 9
  -- AND e.team_id = ?
  AND EXISTS (
      SELECT 1
      FROM crm_contact_links l
      JOIN crm_phone_numbers p
        ON p.phoneable_id = l.contact_id
       AND p.is_active = 1
       AND p.international IS NOT NULL
       AND (p.phoneable_type = 'crm_contact' OR p.phoneable_type LIKE '%CrmContact')
      WHERE l.linkable_id = e.id
        AND l.linkable_type LIKE '%RecEmployee'
  )
;


-- ----------------------------------------------------------------------------
-- 3b. DIE ABWEICHER, EINZELN -- Kennungen ohne Namen
-- ----------------------------------------------------------------------------
SELECT
    e.id                                                                AS mitarbeiter_kennung,
    RIGHT(REGEXP_REPLACE(e.phone, '[^0-9]', ''), 9)                     AS akte_endet_auf,
    (SELECT GROUP_CONCAT(DISTINCT RIGHT(REGEXP_REPLACE(p.international, '[^0-9]', ''), 9))
       FROM crm_contact_links l
       JOIN crm_phone_numbers p
         ON p.phoneable_id = l.contact_id
        AND p.is_active = 1
        AND p.international IS NOT NULL
        AND (p.phoneable_type = 'crm_contact' OR p.phoneable_type LIKE '%CrmContact')
      WHERE l.linkable_id = e.id
        AND l.linkable_type LIKE '%RecEmployee')                        AS kontakt_endet_auf
FROM rec_employees e
WHERE e.is_active = 1
  AND LENGTH(REGEXP_REPLACE(COALESCE(e.phone, ''), '[^0-9]', '')) >= 9
  -- AND e.team_id = ?
  AND EXISTS (
      SELECT 1 FROM crm_contact_links l
      JOIN crm_phone_numbers p
        ON p.phoneable_id = l.contact_id
       AND p.is_active = 1
       AND p.international IS NOT NULL
       AND (p.phoneable_type = 'crm_contact' OR p.phoneable_type LIKE '%CrmContact')
      WHERE l.linkable_id = e.id AND l.linkable_type LIKE '%RecEmployee'
  )
  AND NOT EXISTS (
      SELECT 1 FROM crm_contact_links l
      JOIN crm_phone_numbers p
        ON p.phoneable_id = l.contact_id
       AND p.is_active = 1
       AND p.international IS NOT NULL
       AND (p.phoneable_type = 'crm_contact' OR p.phoneable_type LIKE '%CrmContact')
      WHERE l.linkable_id = e.id
        AND l.linkable_type LIKE '%RecEmployee'
        AND RIGHT(REGEXP_REPLACE(p.international, '[^0-9]', ''), 9)
          = RIGHT(REGEXP_REPLACE(e.phone, '[^0-9]', ''), 9)
  )
ORDER BY e.id
;


-- ----------------------------------------------------------------------------
-- 4. ZUGABE: WIE VIELE PAARE ERWARTET DER BACKFILL?
--
-- Nicht von Canvas 68 verlangt, aber es sagt dir vorab, wie viele Personen-
-- Zeilen entstehen und wie viele Menschen zwei Anstellungen haben.
-- ----------------------------------------------------------------------------
SELECT
    COUNT(*)                                                            AS aktive_gesamt,
    COUNT(NULLIF(TRIM(COALESCE(person_key, '')), ''))                   AS mit_marker,
    COUNT(DISTINCT NULLIF(TRIM(COALESCE(person_key, '')), ''))          AS markergruppen
FROM rec_employees
WHERE is_active = 1
  -- AND team_id = ?
;


-- ############################################################################
-- ABFRAGE 5 -- DIE ENTSCHEIDENDE: TRAEGT DER BESTAND UEBERHAUPT GENUG MARKER?
--
-- Nachgetragen am 29.09.2026, nachdem die Vorflug-Zahlen vorlagen (1572 aktive,
-- 354 Nummerngruppen mit derselben Person, 728 Datensaetze).
--
-- Der Anlass: PersonGroupPlanner::plan() gruppiert AUSSCHLIESSLICH ueber
-- rec_employees.person_key. Wer keinen Marker traegt, bekommt eine EIGENE
-- Personen-Zeile -- auch wenn er nachweislich derselbe Mensch ist.
--
-- Die 354 Paare sind ueber die TELEFONNUMMER sichtbar. Zusammengezogen werden
-- aber nur die mit gemeinsamem MARKER. Fuer alle uebrigen legt der Backfill
-- zwei Personen-Zeilen an, und Gate C ("ab hier ist bekannt, wer zusammen-
-- gehoert") waere verfehlt, obwohl der Lauf fehlerfrei durchlaeuft.
--
-- Diese Abfrage sagt, wie gross die Luecke ist. Ist die mittlere Spalte gross,
-- muss VOR dem Backfill das Paarungs-Audit laufen:
--     php artisan recruiting:person-pair-audit            (erst nur Bericht)
--     php artisan recruiting:person-pair-audit --apply    (dann stempeln)
-- ############################################################################
WITH basis AS (
    SELECT
        e.id,
        NULLIF(TRIM(COALESCE(e.person_key, '')), '')    AS marker,
        DATE(e.birth_date)                              AS geburtstag,
        RIGHT(REGEXP_REPLACE(e.phone, '[^0-9]', ''), 9) AS suffix
    FROM rec_employees e
    WHERE e.is_active = 1
      AND LENGTH(REGEXP_REPLACE(COALESCE(e.phone, ''), '[^0-9]', '')) >= 9
),
gruppen AS (
    SELECT
        suffix,
        COUNT(*)                                                          AS datensaetze,
        (COUNT(marker) = COUNT(*) AND COUNT(DISTINCT marker) = 1)         AS marker_einig,
        (COUNT(geburtstag) = COUNT(*) AND COUNT(DISTINCT geburtstag) = 1) AS geburt_einig
    FROM basis
    GROUP BY suffix
    HAVING COUNT(*) > 1
)
SELECT
    -- Diese zieht der Backfill zusammen: gemeinsamer Marker vorhanden.
    COALESCE(SUM(CASE WHEN marker_einig THEN 1 ELSE 0 END), 0)
        AS backfill_zieht_zusammen,
    -- DIESE NICHT: derselbe Mensch (gleiches Geburtsdatum), aber kein
    -- gemeinsamer Marker. Sie bekommen ZWEI Personen-Zeilen.
    COALESCE(SUM(CASE WHEN NOT marker_einig AND geburt_einig THEN 1 ELSE 0 END), 0)
        AS derselbe_mensch_OHNE_marker,
    COALESCE(SUM(CASE WHEN NOT marker_einig AND geburt_einig THEN datensaetze ELSE 0 END), 0)
        AS davon_datensaetze
FROM gruppen
;


-- ############################################################################
-- ABFRAGE 6 -- WIE VIELE PERSONEN-ZEILEN LEGT DER BACKFILL AN?
--
-- Genau die Zahl, die der Trockenlauf spaeter melden wird -- hier vorab, ohne
-- irgendetwas zu schreiben. Sie bildet die Gruppierungsregel des Backfills
-- nach: je Marker eine Zeile, je markerlosem Datensatz eine eigene.
--
-- Vergleich mit 1572 aktiven Mitarbeitern sagt, wie viel der Backfill
-- ueberhaupt zusammenzieht.
-- ############################################################################
SELECT
    (SELECT COUNT(DISTINCT NULLIF(TRIM(COALESCE(person_key, '')), ''))
       FROM rec_employees WHERE is_active = 1)
        AS zeilen_fuer_gepaarte,
    (SELECT COUNT(*) FROM rec_employees
      WHERE is_active = 1 AND NULLIF(TRIM(COALESCE(person_key, '')), '') IS NULL)
        AS zeilen_fuer_markerlose,
    (SELECT COUNT(DISTINCT NULLIF(TRIM(COALESCE(person_key, '')), ''))
       FROM rec_employees WHERE is_active = 1)
  + (SELECT COUNT(*) FROM rec_employees
      WHERE is_active = 1 AND NULLIF(TRIM(COALESCE(person_key, '')), '') IS NULL)
        AS personen_zeilen_gesamt
;
