<?php

return [
    'name' => 'Recruiting',
    'description' => 'Recruiting Module',
    'version' => '1.0.0',

    'routing' => [
        'prefix' => 'recruiting',
        'middleware' => ['web', 'auth'],
    ],

    'guard' => 'web',

    'navigation' => [
        'main' => [
            'recruiting' => [
                'title' => 'Recruiting',
                'icon' => 'heroicon-o-briefcase',
                'route' => 'recruiting.dashboard',
            ],
        ],
    ],

    'sidebar' => [
        [
            'group' => 'Recruiting',
            'items' => [
                ['label' => 'Dashboard',       'route' => 'recruiting.dashboard',                'icon' => 'heroicon-o-home'],
                ['label' => 'Stellen',         'route' => 'recruiting.positions.index',          'icon' => 'heroicon-o-briefcase'],
                ['label' => 'Ausschreibungen', 'route' => 'recruiting.postings.index',           'icon' => 'heroicon-o-megaphone'],
                ['label' => 'Bewerber',        'route' => 'recruiting.applicants.index',         'icon' => 'heroicon-o-user-group'],
                ['label' => 'Eingangs-Inbox',  'route' => 'recruiting.inbox.index',              'icon' => 'heroicon-o-inbox'],
                ['label' => 'WhatsApp-Kosten', 'route' => 'recruiting.whatsapp-costs.index', 'icon' => 'heroicon-o-banknotes'],
            ],
        ],
    ],
    'billables' => [
        [
            'model' => \Platform\Recruiting\Models\RecPosting::class,
            'type' => 'per_item',
            'label' => 'Stellenausschreibung',
            'description' => 'Jede erstellte Stellenausschreibung verursacht tägliche Kosten nach Nutzung.',
            'pricing' => [
                ['cost_per_day' => 0.005, 'start_date' => '2025-01-01', 'end_date' => null]
            ],
            'free_quota' => null,
            'min_cost' => null,
            'max_cost' => null,
            'billing_period' => 'daily',
            'start_date' => '2026-01-01',
            'end_date' => null,
            'trial_period_days' => 0,
            'discount_percent' => 0,
            'exempt_team_ids' => [],
            'priority' => 100,
            'active' => true,
        ],
        [
            'model' => \Platform\Recruiting\Models\RecApplicant::class,
            'type' => 'per_item',
            'label' => 'Bewerber',
            'description' => 'Jeder angelegte Bewerber verursacht tägliche Kosten nach Nutzung.',
            'pricing' => [
                ['cost_per_day' => 0.0025, 'start_date' => '2025-01-01', 'end_date' => null]
            ],
            'free_quota' => null,
            'min_cost' => null,
            'max_cost' => null,
            'billing_period' => 'daily',
            'start_date' => '2026-01-01',
            'end_date' => null,
            'trial_period_days' => 0,
            'discount_percent' => 0,
            'exempt_team_ids' => [],
            'priority' => 100,
            'active' => true,
        ],
    ],

    'whatsapp_costs' => [
        // Meta Utility-Template an DE-Empfänger, direkt über Cloud API. Stand 04/2026.
        // Bei Meta-Ratenänderung hier anpassen (kein Hardcoding im Code).
        'price_per_delivered_template' => 0.055,
        // Service-Aufschlag in Prozent, der auf den Meta-Basispreis kommt. Der dem
        // Kunden angezeigte Preis enthält diesen Aufschlag bereits (nicht separat ausgewiesen).
        'fee_percent' => 30,
        'currency' => 'EUR',
    ],

    /*
    |--------------------------------------------------------------------------
    | ZAS Bewerber-Export
    |--------------------------------------------------------------------------
    |
    | Konfiguration fuer den ZAS-Pull-Endpoint (externes IBEI-HR-System).
    | Siehe docs/meingedeck/zas-applicant-export.md
    |
    | - token:                Bearer-Token fuer Authorization-Header. Pflicht.
    |                         Lange Zufallsstring (>= 32 Zeichen). Niemals per
    |                         Klartext-Mail uebergeben — Bitwarden o. ä.
    | - signed_url_secret:    HMAC-Sekret fuer die Datei-URLs. Pflicht.
    |                         Bei Rotation werden alle bestehenden Foto-Links
    |                         in ZAS sofort ungueltig — also nur rotieren
    |                         wenn man weiss was man tut.
    | - signed_url_ttl_days:  Lebensdauer der Foto-Links. ZAS sollte die
    |                         Dateien beim Pull sofort lokal kopieren —
    |                         URLs sind nicht fuer Langzeit-Persistenz.
    | - export_min_phase_order:
    |                         Optional zusaetzliches Phase-Gate. NULL =
    |                         deaktiviert; primaerer Filter ist sowieso
    |                         "Bewerber hat versendeten Vertrag". Falls
    |                         spaeter strenger gefiltert werden soll
    |                         (z. B. nur Phase >= 4 in der neuen Logik).
    */
    'zas' => [
        'token'                  => env('RECRUITING_ZAS_TOKEN'),
        'signed_url_secret'      => env('RECRUITING_ZAS_SIGNED_URL_SECRET'),
        'signed_url_ttl_days'    => (int) env('RECRUITING_ZAS_SIGNED_URL_TTL_DAYS', 7),
        'export_min_phase_order' => env('RECRUITING_ZAS_EXPORT_MIN_PHASE_ORDER'),

        // Storage-Disk fuer von ZAS eingehende CSVs (POST /recruiting/zas/inbound).
        // Default 'local' = privat, nicht oeffentlich erreichbar.
        'inbound_disk'           => env('RECRUITING_ZAS_INBOUND_DISK', 'local'),

        // Team, dem von ZAS importierte Mitarbeiter zugeordnet werden (Pflicht fuer Import).
        'inbound_team_id'        => env('RECRUITING_ZAS_INBOUND_TEAM_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | FLYNK-Sync (Ausschreibungen → Website-Tasks)
    |--------------------------------------------------------------------------
    |
    | Ausgehender Sync veröffentlichter Ausschreibungen an den FLYNK
    | Task-Webhook. Ohne enabled=true + token passiert nichts.
    | Siehe docs/superpowers/specs/2026-07-06-flynk-ausschreibungen-sync-design.md
    */
    'flynk' => [
        'enabled'      => (bool) env('RECRUITING_FLYNK_ENABLED', false),
        'base_url'     => env('RECRUITING_FLYNK_BASE_URL', 'https://flynk.on-forge.com/api'),
        'token'        => env('RECRUITING_FLYNK_TOKEN'),
        'careers_url'  => env('RECRUITING_FLYNK_CAREERS_URL'),
        'timeout'      => (int) env('RECRUITING_FLYNK_TIMEOUT', 10),
        'per_run_cap'  => (int) env('RECRUITING_FLYNK_PER_RUN_CAP', 50),
        'max_attempts' => (int) env('RECRUITING_FLYNK_MAX_ATTEMPTS', 5),
    ],
];
