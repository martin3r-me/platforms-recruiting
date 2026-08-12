`grep -rn "RecContractTemplate\|rec_contract_templates" --include=*.php --include=*.blade.php src/ database/ resources/ routes/ tests/ | grep -v worktrees`

Ergänzend, weil `ContractPreSigningType` weder den Klassennamen noch den Tabellennamen enthält und vom Haupt-Grep nicht erfasst wird: `grep -rn "RESTTAGE_CODES\|forCode" src/Support/ContractPreSigningType.php src/Livewire/Public/ContractSigning.php`

Stand: main `511451c6901ea830afc77ad1c0bd0e6cfde6a2ff`. Spalte `type` existiert zu diesem Stand **nicht** (Migration `2026_04_15_100000_create_rec_contract_tables.php:11-30`, `$fillable` in `RecContractTemplate.php:20-32` ohne `type`) — „filtert heute" nennt deshalb die tatsächlich vorhandenen Filter, nicht `type`.

Dritter Grep, auf ID-Ebene statt Objekt-Ebene — findet Stellen, die eine Vorlagen-ID nur durchreichen, ohne die Vorlage als Objekt anzufassen: `grep -rn "contract_template_id\|contractTemplateId\|additional_contract_template_id\|additionalContractTemplateId" --include=*.php --include=*.blade.php src/ database/ resources/ routes/ tests/ | grep -v worktrees`. Die so gefundenen Zeilen sind mit „per ID-Grep gefunden" vermerkt.

**Alle „keiner"-Zeilen mit code-Muster-Filter hängen an §B8 (`ZERT-`-Präfixzwang). §B8 ist einzelne Ausfallstelle für 12 Einträge; der Test dazu ist Pflicht.** Die Filter `AV%`, `AT-%`, `AV-default` und `IFSG` schließen ein Zertifikat nur aus, solange dessen `code` keinen dieser Präfixe trägt — und das garantiert allein §B8.

**Merge-Gate: die 22 Zeilen mit Handlungsbedarf müssen in Spalte `erledigt` abgehakt sein.** Handlungsbedarf hat eine Zeile genau dann, wenn ihr Soll-Filter einen Filter *hinzufügt*. Nicht dazu gehören: Zeilen mit Soll-Filter „keiner" (inklusive der geerbten), sowie die zwei ausdrücklich mit **n/a** markierten Zeilen (`ContractTemplates/Index.php:46` und `CopyHcmContractTemplates.php:79`), bei denen die richtige Handlung „nichts ändern" ist und ein Häkchen deshalb irreführend wäre.

| Datei:Zeile | Kontext | filtert heute | Soll-Filter | erledigt |
| --- | --- | --- | --- | --- |
| src/Services/SendContractsService.php:62 | AV-Auflösung aus applicant.contract_template_id — Haupttrichter jeder Versandstrecke | team_id, id, is_active | type='contract' | |
| src/Services/SendContractsService.php:74 | IFSG-Auflösung beim Versand | team_id, code='IFSG', is_active | keiner | |
| src/Livewire/Applicant/Show.php:661 | Dropdown availableContractTemplates | team_id, is_active, deleted_at | type='contract' | |
| src/Livewire/Applicant/Show.php:696 | Validierungsregel exists:rec_contract_templates,id | nichts | Rule-Objekt mit type='contract' | |
| src/Livewire/Applicant/Show.php:750 | findOrFail($templateId) vor der Vertragsanlage — ungefiltert, davor nur die exists-Regel aus :696 | nichts | type='contract' | |
| src/Livewire/Applicant/Show.php:789 | autoAttachIfsgTemplate | team_id, code='IFSG', is_active, deleted_at | keiner | |
| src/Livewire/DirectHire/Index.php:229 | Dropdown availableContractTemplates (Direkteinstellung) | forTeam, active, deleted_at | type='contract' | |
| src/Livewire/DirectHire/Index.php:288 | Vorlagen-Auflösung bei der MA-Anlage | forTeam, active, deleted_at | type='contract' | |
| src/Livewire/InterviewBookings/Index.php:418 | setApplicantContractTemplate — public, über Wire-Protokoll erreichbar | team_id, id, is_active | type='contract' | |
| src/Livewire/InterviewBookings/Index.php:1053 | defaultContractTemplate | team_id, code='AV-default', is_active | keiner | |
| src/Livewire/Contracts/Index.php:53 | Filter-Dropdown der Vertragsliste | team_id, is_active | type='contract' (kosmetisch) | |
| src/Livewire/HrDesk/Index.php:212 | Dropdown der AT-*-Zusatzverträge | team_id, is_active, code LIKE 'AT-%' | keiner | |
| src/Livewire/HrDesk/Index.php:262 | Validierung in setAdditionalContractTemplate | team_id, is_active, code LIKE 'AT-%', id | keiner | |
| src/Livewire/HrDesk/Index.php:288 | defaultContractTemplate | team_id, code='AV-default', is_active | keiner | |
| src/Livewire/ContractTemplates/Index.php:46 | Admin-Liste der Vorlagen | team_id | **n/a** — bewusst ungefiltert, beide Typen sollen hier sichtbar sein; richtige Handlung ist „nichts ändern" | n/a |
| src/Livewire/ContractTemplates/Index.php:61 | openEditModal, findOrFail | nichts | keiner (Admin-Fläche) | |
| src/Livewire/ContractTemplates/Index.php:99 | Update einer Bestandsvorlage, findOrFail | nichts | type in $rules aufnehmen | |
| src/Livewire/ContractTemplates/Index.php:104 | create($data) | entfällt | type in $data + saving-Hook (§B8) | |
| src/Livewire/ContractTemplates/Index.php:114 | delete, findOrFail | nichts | keiner | |
| src/Tools/CreateContractTool.php:87 | MCP-Vertragsanlage, Vorlagen-Lookup | team_id | type='contract' + VALIDATION_ERROR | |
| src/Tools/ListContractTemplatesTool.php:56 | MCP-Vorlagenliste | team_id, optional is_active | optionaler type-Parameter, Default 'contract' | |
| src/Tools/CreateContractTemplateTool.php:87 | MCP-Vorlagenanlage | entfällt | type durchreichen + saving-Hook (§B8) | |
| src/Tools/UpdateContractTemplateTool.php:86 | MCP-Vorlagen-Update, validateAndFindModel | team-Scope des Helpers | type durchreichen + saving-Hook (§B8) | |
| src/Tools/DeleteContractTemplateTool.php:62 | MCP-Vorlagen-Delete, validateAndFindModel | team-Scope des Helpers | keiner | |
| src/Tools/UpdateApplicantTool.php:193 | FK-Existenzprüfung für contract_template_id über Klassen-Map | kein Filter | type='contract' | |
| src/Support/ContractPreSigningType.php:23-38 | Wahl des Vorschalt-Schritts vor der Unterschrift | code in RESTTAGE_CODES bzw. Präfix 'AV-' | keiner an dieser Stelle; stattdessen erzwungener code-Präfix 'ZERT-' für type='certificate' im saving-Hook (§B8) | |
| src/Http/Controllers/ZasFileController.php:108-115 | ZAS-Dateiauswahl per Join auf die Vorlage | code LIKE 'AV%' bzw. code='IFSG' | keiner | |
| src/Http/Controllers/ZasEmployeeFileController.php:107-114 | ZAS-Dateiauswahl (MA-Variante) | code LIKE 'AV%' bzw. code='IFSG' | keiner | |
| src/Services/Zas/ZasFieldResolver.php:348 | Template-code eines Vertrags lesen | kein Filter, nur Lesen | keiner | |
| src/Services/Zas/ZasFieldResolver.php:415-421 | ZAS-Dateiauswahl per Join | code LIKE 'AV%' bzw. code='IFSG' | keiner | |
| src/Services/Zas/ZasEmployeeFieldResolver.php:372-377 | ZAS-Dateiauswahl per Join | code LIKE 'AV%' bzw. code='IFSG' | keiner | |
| src/Services/Zas/ZasEmployeeFieldResolver.php:411-413 | IFSG-Join | code='IFSG' | keiner | |
| src/Services/Zas/ZasEmployeeFieldResolver.php:509-511 | applicant.contract_template_id auf code auflösen | kein Filter, nur Lesen | keiner | |
| src/Console/Commands/CreateArbeitsvertragVariants.php:50 | Basis-Vorlagen einlesen | is_active, code | type='contract' | |
| src/Console/Commands/CreateArbeitsvertragVariants.php:110 | roher DB::table-Insert einer AV-Variante | entfällt | type='contract' **explizit setzen**. Begründung: das Command erzeugt gezielt Arbeitsverträge; der Typ ist hier eine Zusicherung des Commands, nicht ein Zufall der Spaltendefinition. Fällt der DEFAULT irgendwann weg oder ändert sich, muss dieses Command weiterhin Verträge erzeugen | |
| src/Console/Commands/CreateArbeitsvertragVariants.php:138 | roher DB::table-Update | entfällt | keiner | |
| src/Console/Commands/CreateArbeitsvertragVariants.php:155 | obsolete Varianten ermitteln | code-Muster | type='contract' | |
| src/Console/Commands/CopyHcmContractTemplates.php:79 | roher DB::table-Insert aus HCM | entfällt | **n/a** — Insert unverändert lassen, Spalte über NOT NULL DEFAULT 'contract' abgedeckt. Begründung, warum hier anders als bei CreateArbeitsvertragVariants:110: dieses Command kopiert fremde Daten 1:1 und trifft bewusst keine Typaussage über sie; der DEFAULT ist genau der richtige Ort für „unbekannt, also Vertrag". Eine explizite Zuweisung wäre eine Behauptung über HCM-Inhalte, die das Command nicht prüfen kann | n/a |
| src/Console/Commands/SeedRecContractExtraFields.php:51 | distinct team_id über Vorlagen | deleted_at | keiner | |
| src/Models/RecApplicant.php:342 | belongsTo contractTemplate | entfällt | keiner (Leserichtung) | |
| src/Models/RecApplicantLegalStatus.php:97 | belongsTo additionalContractTemplate | entfällt | keiner (Leserichtung) | |
| src/Models/RecContract.php:107 | belongsTo contractTemplate | entfällt | keiner (Leserichtung) | |
| src/Services/ContractDispatchService.php:32 | ?RecContractTemplate $defaultTemplate als Parameter | entfällt | keiner (Weitergabe, Auflösung liegt beim Aufrufer) | |
| src/RecruitingServiceProvider.php:92 | Morph-Map-Eintrag rec_contract_template | entfällt | keiner | |
| src/Services/SendContractsService.php:144-145 | Zusatzvertrag-Auflösung über `legalStatus?->additionalContractTemplate`, danach Contract-Anlage auf `:154-160` mit `personalizeContent()` — **dritte Vertragsanlage in diesem Service** | nur `is_active` | **type='contract'** — heute nur transitiv geschützt über HrDesk:262 (`code LIKE 'AT-%'`) plus §B8; ein Zertifikat in `additional_contract_template_id` würde hier einen echten Vertrag erzeugen | per ID-Grep gefunden |
| src/Services/ContractDispatchService.php:40-41 | schreibt `contract_template_id` auf den Bewerber aus `$defaultTemplate` | kein eigener Filter | keiner — das Objekt kommt von HrDesk:288 bzw. InterviewBookings:1053, beide `code`-gefiltert | per ID-Grep gefunden |
| src/Livewire/InterviewBookings/Index.php:427 | Zuweisung der ID nach dem Lookup `:418` | erbt `:418` | keiner — erbt `:418`, dort gemeinsam erledigen | per ID-Grep gefunden |
| src/Livewire/InterviewBookings/Index.php:1065-1070 | Auto-Zuweisung des Default-AV an den Bewerber | Objekt aus `:1053` (`code='AV-default'`) | keiner | per ID-Grep gefunden |
| src/Livewire/DirectHire/Index.php:299 | Zuweisung der ID nach dem Lookup `:288` | erbt `:288` | keiner — erbt `:288`, dort gemeinsam erledigen | per ID-Grep gefunden |
| src/Livewire/HrDesk/Index.php:272 | Zuweisung `additional_contract_template_id` nach der Validierung `:262` | erbt `:262` | keiner — erbt `:262`, dort gemeinsam erledigen | per ID-Grep gefunden |
| src/Livewire/InterviewBookings/Index.php:524 | Bulk-Versand-Eignung, prüft nur Präsenz der ID | keiner | keiner — ein Typbruch schlägt erst in SendContractsService:62 fehl, nicht hier | per ID-Grep gefunden |
| src/Livewire/InterviewBookings/Index.php:619 | zweiter Bulk-Pfad, prüft nur Präsenz der ID | keiner | keiner — wie `:524` | per ID-Grep gefunden |
| resources/views/livewire/interview-bookings/index.blade.php:385 | UI-Zustand („Vorlage gesetzt") aus Präsenz der ID | keiner | keiner — kosmetisch; zeigte bei einem Typbruch fälschlich „gesetzt" | per ID-Grep gefunden |
| resources/views/livewire/interview-bookings/index.blade.php:447 | Zähler „mit Vorlage" aus Präsenz der ID | keiner | keiner — kosmetisch | per ID-Grep gefunden |
| src/Models/RecApplicantLegalStatus.php:126 | nullt `additional_contract_template_id` bei EU-Status-Wechsel | entfällt | keiner | per ID-Grep gefunden |
| src/Tools/ListContractsTool.php:71-72 | filtert Verträge nach `contract_template_id` | kein Vorlagen-Filter | keiner — Leserichtung auf Verträge, nicht auf Vorlagen | per ID-Grep gefunden |
| src/Console/Commands/CreateArbeitsvertragVariants.php:163 | hängt Verträge alter Varianten um | `rec_contract_template_id` | keiner | per ID-Grep gefunden |
| src/Console/Commands/DiagnoseContractExtraFields.php:18,25 | Diagnose-Ausgabe inkl. Template-ID | entfällt | keiner | per ID-Grep gefunden |
| src/Services/IssueTrainingCertificateService.php (neu) | Vorlagen-Auflösung bei der Ausstellung — **Gegenrichtung**: darf hier ein *Vertrag* als Zertifikat ausgestellt werden? | existiert noch nicht | **type='certificate'** | neu (§C) |
| src/Livewire/HrDesk/Index.php (neu, §C3) | Vorlagen-Dropdown im Ablehnen-Zweig — **Gegenrichtung** | existiert noch nicht | **type='certificate', is_active** | neu (§C) |
| src/Models/RecApplicantSettings.php — `default_certificate_template_id` (neu, §C4) | Vorlagenwahl für Weg (b) — **Gegenrichtung** | existiert noch nicht | **Wert muss zur Ausstellungszeit noch `type='certificate'` sein** — Prüfung bei der Ausstellung, nicht beim Speichern: der Typ einer Vorlage kann sich nach dem Setzen des Settings ändern | neu (§C) |
