<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;

/**
 * Telefon loest den ZAS-Update-Marker NICHT aus — bewusste Pause (02.09.2026).
 *
 * Anlass: der Bestands-Fix recruiting:normalize-employee-phones hat ueber den
 * phone-Trigger ~500 ZAS-Bestands-MA in den Update-Export gespuelt. Der Export
 * liefert immer VOLLE Zeilen; fuer die Bestands-MA sind viele Felder bei uns
 * leer bzw. aelter als in ZAS — ein Import auf ZAS-Seite haette dessen
 * gepflegte Akten ueberschrieben (Vorfall Clara/Markus, 02.09.).
 *
 * Die Pause gilt, bis der Export nur noch tatsaechlich geaenderte Felder
 * liefert (Diff-Export mit Snapshot). Danach darf phone wieder in die Liste —
 * dieser Test ist dann bewusst umzudrehen, nicht nur zu loeschen.
 */
class ZasExportPhoneTriggerPausedTest extends TestCase
{
    public function test_phone_does_not_trigger_the_zas_update_marker(): void
    {
        $this->assertNotContains('phone', RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS);
    }

    public function test_email_still_triggers(): void
    {
        // Gegenprobe: die Pause gilt NUR fuer phone — nicht versehentlich
        // den ganzen Kontakt-Block mit entfernen.
        $this->assertContains('email', RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS);
    }
}
