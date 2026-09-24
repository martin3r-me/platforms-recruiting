<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Mein Portal' }}</title>

    {{--
        Eigenes Layout, bewusst OHNE platform::layouts.guest.

        Drei Gruende, alle aus Canvas 67 bzw. schmerzhafter Erfahrung:
          · Die Markenfarben sollen NUR hier gelten, nicht in der uebrigen App.
          · Kein Tailwind, kein x-ui-styles — damit erbt kein Eingabefeld ein
            dark:text-white in eine weisse Karte (Vorfall 08/2026).
          · Der Entwurf bringt seine eigene CSS mit. Sie zu uebernehmen ist
            treuer als sie mit fremden Bausteinen nachzubauen.

        Folge: Im neuen Portal werden KEINE x-ui-Komponenten benutzt. Reines
        HTML mit der Stilvorlage des Entwurfs.
    --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=chau-philomene-one:400|inter:400,500,600,700&display=swap">

    @include('recruiting::layouts.portal-styles')

    {{--
        Aus dem Telefonrahmen ausbrechen.

        Im Entwurf steckt der Bildschirm in einem gezeichneten Handy: feste
        690px Hoehe, runde Ecken, Rahmen. Im echten Portal IST der Bildschirm
        das Fenster. Nur diese wenigen Regeln aendern sich — alles andere
        bleibt wie abgenommen.
    --}}
    @verbatim
    <style>
        html, body { height: 100% }

        /* Native Bedienelemente (Kalender im Datumsfeld) sollen der Seite
           folgen. Ohne das steht im Dunkelmodus ein dunkler Kalender-Glyph in
           einem hellen Feld und ist nicht zu sehen — der Fix vom 06.08.2026
           im alten Portal, hier als Regel statt als erzwungenes Hell. */
        :root { color-scheme: light }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) { color-scheme: dark }
        }
        body.portal-body {
            margin: 0;
            background: var(--ground);
            color: var(--ink);
            font-family: var(--body);
            -webkit-font-smoothing: antialiased;
        }
        /* Der Bildschirm fuellt das Fenster; auf breiten Schirmen mittig und
           schmal, damit die Zeilen lesbar bleiben. */
        .portal-body .screen {
            height: 100dvh;
            max-width: 520px;
            margin: 0 auto;
            border-radius: 0;
            background: var(--surface);
        }
        @supports not (height: 100dvh) { .portal-body .screen { height: 100vh } }
        @media (min-width: 560px) {
            .portal-body .screen { box-shadow: var(--shadow) }
        }
        /* Fusszeile ueber der Heimtaste des iPhones freihalten. */
        .portal-body .tabbar { padding-bottom: calc(13px + env(safe-area-inset-bottom)) }

        /* Solange Alpine noch nicht wach ist, ist kein Bereich markiert —
           dann zeigt der erste. Ohne das blitzt beim Laden eine leere Seite. */
        .portal-body .scroll:not(:has(.pane.on)) > .pane:first-of-type { display: flex }

        /* --- Anmeldung: im Entwurf nicht enthalten, im Geist des Entwurfs --- */
        .portal-body .card.login { padding: 17px; display: flex; flex-direction: column; gap: 15px }
        .portal-body .feld { display: flex; flex-direction: column; gap: 6px }
        .portal-body .feld .n { font-size: 13px; font-weight: 600; color: var(--ink-2) }
        .portal-body .feld input {
            font-family: var(--body); font-size: 16px; color: var(--ink);
            background: var(--surface-2); border: 1px solid var(--line-2);
            border-radius: var(--r); padding: 11px 12px; width: 100%;
            box-sizing: border-box; appearance: none;
        }
        .portal-body .feld input:focus {
            outline: none; border-color: var(--brand);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 22%, transparent);
        }
        /* 16px ist Absicht: darunter zoomt iOS beim Antippen ins Feld hinein. */

        /* --- Kleinigkeiten, die der Entwurf nicht kannte --- */
        /* Fehlermeldung der Anmeldung: rot, nicht gelb — .alert bringt von
           Haus aus den warnenden Ton mit. */
        .portal-body .alert.crit { background: var(--crit-bg) }
        .portal-body .alert.crit .txt { color: var(--crit) }

        .portal-body .leer { padding: 17px; font-size: 14px; color: var(--ink-3); line-height: 1.5 }
        .portal-body .fuss { margin: 9px 2px 0; font-size: 12.5px; color: var(--ink-3); line-height: 1.5 }
    </style>
    @endverbatim
    @livewireStyles
</head>
<body class="portal-body">
    {{ $slot }}
    @livewireScripts
</body>
</html>
