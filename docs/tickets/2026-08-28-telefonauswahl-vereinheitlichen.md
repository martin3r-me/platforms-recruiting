# Telefonnummern-Auswahl fuer WhatsApp-Versand vereinheitlichen

**Gefunden:** 28.08.2026 im Final-Review der Kampagne „Neue Termine“.

## Befund

Im Modul gibt es drei Sendepfade mit zwei verschiedenen Regeln, welche Nummer eines Bewerbers angeschrieben wird:

- `RecApplicant::sendBookingLinkWhatsApp()` (Auto-Pilot-Buchungslink, Warteliste, Holding): bevorzugt `is_primary` **und** verlangt `international` gesetzt; sonst erste aktive Nummer mit `international`.
- `RecApplicant::primaryContactPhone()` (Zertifikat-Versand, Kampagne „Neue Termine“, Dispo-Fallbacks): erste aktive Nummer, `international` **oder** Fallback `raw_input`.

Der `raw_input`-Fallback kann eine nationale `015…`-Nummer liefern. Meta baut daraus eine US-wa_id; der Fehler kommt erst per Webhook (`failed`), der Versand zaehlt lokal als Erfolg (siehe Memory „Dispo-Alarm ans Diensthandy scheitert“). Bei einer Kampagne mit 150 Empfaengern faellt das nicht auf.

## Vorschlag

- Eine Regel, eine Methode: `primaryContactPhone()` liefert nur E.164 (`international`), `raw_input` nie als Sendeziel; kein Treffer → `null` (Badge „kein Telefon“ / Skip).
- `sendBookingLinkWhatsApp()` auf dieselbe Methode umstellen (Verhalten dort bleibt: primary bevorzugt).
- Datenpflege: Nummern ohne `international` einmalig normalisieren (Bestand pruefen: `SELECT COUNT(*) FROM crm_phone_numbers WHERE is_active=1 AND international IS NULL`).

## Nicht Teil dieses Tickets

Meta-`failed`-Webhook → Status im Kampagnen-Log zurueckschreiben (eigenes Thema, betrifft auch HR-Versand, Memory „MA-Portal Auth-Bypass … sendPortalNotification stempelt Meta-failed als Erfolg“).
