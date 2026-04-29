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
];
