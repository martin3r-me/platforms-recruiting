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
        .portal-body .feld input, .portal-body .feld select {
            font-family: var(--body); font-size: 16px; color: var(--ink);
            background: var(--surface-2); border: 1px solid var(--line-2);
            border-radius: var(--r); padding: 11px 12px; width: 100%;
            box-sizing: border-box; appearance: none;
        }
        .portal-body .feld input:focus, .portal-body .feld select:focus {
            outline: none; border-color: var(--brand);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 22%, transparent);
        }
        /* 16px ist Absicht: darunter zoomt iOS beim Antippen ins Feld hinein. */

        /* ---------- Handy: die Seitenleiste des Entwurfs schlaeft ---------- */
        .portal-body .brail { display: none }

        /* Die zwei Listen stehen auf dem Handy untereinander. Der Entwurf
           setzt .bcols von Haus aus zweispaltig — das gilt erst am Rechner. */
        .portal-body .bcols { display: flex; flex-direction: column; gap: 17px }

        /* ---------- Rechner: Seitenleiste statt Reiterleiste ----------
           So sieht es im Entwurf aus (.bbody/.brail/.bmain). Dieselben
           Bereiche, dasselbe Markup — nur ein anderes Gestell. */
        @media (min-width: 900px) {
            .portal-body .screen {
                display: grid;
                grid-template-columns: 240px minmax(0, 1fr);
                max-width: 1120px;
                height: calc(100dvh - 48px);
                margin: 24px auto;
                border: 1px solid var(--line);
                border-radius: 14px;
                overflow: hidden;
            }
            /* Kopf- und Fussleiste sind die Handy-Fassung. */
            .portal-body .screen > .appbar,
            .portal-body .screen > .tabbar { display: none }
            .portal-body .brail { display: flex }

            /* min-height:0 ist Pflicht: ohne das waechst ein Grid-Kind an
               seinem Inhalt und scrollt nicht, sondern schiebt. */
            .portal-body .scroll { padding: 26px 30px 34px; gap: 19px; min-height: 0 }
            .portal-body .brail { min-height: 0; overflow-y: auto }
            .portal-body .greet h2 { font-size: 27px }
            .portal-body .next { padding: 21px }
            .portal-body .next h3 { font-size: 25px }

            /* Anmeldung: eine schmale Karte in der Mitte, nicht der
               Zweispalter des angemeldeten Portals. */
            .portal-body .screen.anmeldung {
                display: flex;
                grid-template-columns: none;
                max-width: 430px;
                height: auto;
                margin: 7vh auto;
            }
            .portal-body .screen.anmeldung .scroll {
                padding: 26px 28px 32px;
                overflow: visible;
            }

            .portal-body .bcols {
                display: grid;
                grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
                align-items: start;
                gap: 19px;
            }
        }

        /* Die Navigationspunkte sind bei uns Knoepfe, im Entwurf waren es
           unbedienbare Divs — die Knopf-Eigenheiten wegraeumen. */
        .portal-body .rnav {
            border: none; background: none; width: 100%;
            font-family: var(--body); text-align: left; cursor: pointer;
        }
        .portal-body .rnav:hover { background: var(--surface-3) }
        .portal-body .rnav.on:hover { background: var(--brand-tint) }

        /* --- Kleinigkeiten, die der Entwurf nicht kannte --- */
        /* Fehlermeldung der Anmeldung: rot, nicht gelb — .alert bringt von
           Haus aus den warnenden Ton mit. */
        .portal-body .alert.crit { background: var(--crit-bg) }
        .portal-body .alert.crit .txt { color: var(--crit) }

        .portal-body .leer { padding: 17px; font-size: 14px; color: var(--ink-3); line-height: 1.5 }
        .portal-body .fuss { margin: 9px 2px 0; font-size: 12.5px; color: var(--ink-3); line-height: 1.5 }

        /* Aufgabenzeilen sind jetzt Knoepfe (Upload antippen) — der Entwurf
           kannte sie nur als Deko-Divs. */
        .portal-body .task.tap { cursor: pointer; background: none; border: none;
            font-family: var(--body); text-align: left; width: 100%; padding-right: 13px }
        .portal-body .task.tap:hover { background: var(--surface-2) }

        /* --- Upload-Formular: Ueberlagerung ueber dem ganzen Bildschirm --- */
        .portal-body .upload-overlay {
            /* fixed statt absolute: .screen hat keinen positionierten
               Vorfahren, ein absolute-Overlay wuerde sich am Wurzelelement
               ausrichten — das gilt zufaellig genauso, ist aber nicht
               garantiert, sobald sich .screen einmal aendert. */
            position: fixed; inset: 0; z-index: 20;
            background: color-mix(in srgb, black 45%, transparent);
            display: flex; align-items: flex-end; justify-content: center;
        }
        @media (min-width: 900px) {
            .portal-body .upload-overlay { align-items: center }
        }
        .portal-body .upload-sheet {
            width: 100%; max-width: 440px; background: var(--surface);
            border-radius: 16px 16px 0 0; padding: 20px 19px calc(19px + env(safe-area-inset-bottom));
            display: flex; flex-direction: column; gap: 13px;
            box-shadow: var(--shadow);
        }
        @media (min-width: 900px) {
            .portal-body .upload-sheet { border-radius: 14px; padding: 24px }
        }
        .portal-body .upload-head { display: flex; align-items: center; justify-content: space-between; gap: 12px }
        .portal-body .upload-head h3 { margin: 0; font-family: var(--display); font-size: 20px }
        .portal-body .upload-close {
            border: none; background: var(--surface-3); color: var(--ink-2);
            width: 30px; height: 30px; border-radius: 50%; font-size: 18px;
            line-height: 1; cursor: pointer; flex: none;
        }
        .portal-body .upload-sub { margin: -6px 0 0; font-size: 13px; color: var(--ink-2); line-height: 1.5 }
        .portal-body .upload-status { font-size: 12.5px; color: var(--ink-2) }
        .portal-body .upload-sheet input[type="file"] {
            font-family: var(--body); font-size: 13.5px; color: var(--ink);
            background: var(--surface-2); border: 1px solid var(--line-2);
            border-radius: var(--r); padding: 11px 12px; width: 100%; box-sizing: border-box;
        }
    </style>
    @endverbatim
    @livewireStyles
</head>
<body class="portal-body">
    {{ $slot }}
    @livewireScripts
</body>
</html>
