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
            ],
        ],
        [
            'group' => 'Einstellungen',
            'items' => [
                ['label' => 'Bewerber-Status', 'route' => 'recruiting.applicant-statuses.index', 'icon' => 'heroicon-o-tag'],
            ],
        ],
    ],
];
