<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Applicant\ApplicantSettingsModal;

/**
 * Kennt die Komponente ihre Settings-Schluessel schon VOR dem Oeffnen?
 *
 * Der Grund ist nicht Kosmetik, sondern der einzige Weg, auf dem eine
 * Select-Auswahl im Modal ueberhaupt am Server ankommt. Der Reihe nach:
 *
 * 1. Das Modal steht auf jeder Bewerberliste im DOM (index.blade.php:428),
 *    nur per x-show versteckt. `openSettings()` laeuft erst beim Klick.
 * 2. Alpine initialisiert das `x-data` der Selects trotzdem sofort beim
 *    Seitenaufbau — versteckt heisst nicht uninitialisiert.
 * 3. Ab 20 Optionen schaltet x-ui-input-select in den Searchable-Modus.
 *    Dessen EINZIGER Weg zurueck zur Livewire-Property ist `@entangle`.
 * 4. Livewires `generateEntangleFunction` (livewire.esm.js) liest den Wert
 *    EINMAL bei dieser Initialisierung:
 *
 *        let livewirePropertyValue = livewireComponent.get(livewireProperty);
 *        if (typeof livewirePropertyValue === "undefined") {
 *            console.error("Livewire Entangle Error: ... cannot be found ...");
 *            return;   // <- keine Bindung, und zwar dauerhaft
 *        }
 *
 *    Steht die Property zu diesem Zeitpunkt nicht da, gibt es keine Bindung.
 *    Alpine initialisiert ein bereits initialisiertes x-data spaeter NICHT
 *    erneut — das spaetere `openSettings()` heilt es also nicht mehr.
 *
 * Mit `$settings = []` ist `$wire.get('settings.<key>')` genau dieses
 * `undefined`: die Auswahl bleibt im Browser haengen, `save()` schreibt den
 * alten Wert zurueck, und es sieht aus, als ignoriere das Speichern die
 * Auswahl. Gemessen am 2026-08-13 am Zertifikat-Template; `null` ist dagegen
 * unproblematisch (`typeof null === 'object'`), es zaehlt nur die EXISTENZ.
 *
 * Der Test liest die gebundenen Schluessel aus dem Blade, statt sie zu
 * doppeln: ein neu hinzugefuegtes Select ist damit automatisch mitgeprueft.
 */
class SettingsModalEntangleKeysTest extends TestCase
{
    /**
     * Jeder im Modal gebundene settings-Schluessel muss schon im frischen
     * Zustand der Komponente existieren — also so, wie Livewire sie beim
     * ersten Seitenaufbau rendert, ohne jedes `openSettings()`.
     */
    public function testJederGebundeneSchluesselExistiertVorDemOeffnen(): void
    {
        $modal = new ApplicantSettingsModal();
        $gebunden = $this->gebundeneSettingsSchluessel();

        $this->assertNotEmpty($gebunden, 'Im Blade wurde kein einziges settings-Binding gefunden — liest der Test die richtige Datei?');

        $fehlend = array_values(array_filter(
            $gebunden,
            fn (string $key) => !array_key_exists($key, $modal->settings)
        ));

        $this->assertSame(
            [],
            $fehlend,
            "Diese Schluessel fehlen im frischen Zustand der Komponente, ihr entangle stirbt beim Seitenaufbau:\n  "
                . implode("\n  ", $fehlend)
        );
    }

    /**
     * Alle `wire:model`-Bindungen des Modals, die auf einen Schluessel im
     * settings-Array zeigen — mit und ohne Modifier (.live, .blur, …).
     *
     * @return list<string>
     */
    private function gebundeneSettingsSchluessel(): array
    {
        $blade = dirname(__DIR__, 2) . '/resources/views/livewire/applicant/applicant-settings-modal.blade.php';
        if (!file_exists($blade)) {
            throw new \RuntimeException("Blade des Modals nicht gefunden: {$blade}");
        }

        preg_match_all(
            '/wire:model[\w.]*\s*=\s*"settings\.([\w]+)"/',
            (string) file_get_contents($blade),
            $treffer
        );

        return array_values(array_unique($treffer[1]));
    }
}
