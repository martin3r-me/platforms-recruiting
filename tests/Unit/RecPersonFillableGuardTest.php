<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecPerson;
use ReflectionClass;

/**
 * I4 (Schlusspruefung): "Geschrieben wird die Personen-Zeile ausschliesslich
 * ueber PersonLinker" war fuer rec_employees.rec_person_id technisch
 * erzwungen (Ruling T3-D), fuer die Personen-Zeile selbst aber nur
 * verabredet. Diese vier Spalten tragen Entscheidungen mit Nebenwirkungen,
 * die nur PersonLinker kennt — vor allem phone, das auf alle Anstellungen
 * mitwandern muss, sonst geht der Einmalcode in Stufe 2 an die alte Nummer
 * (der Aussperr-Fall).
 *
 * Gelesen per Reflection ohne Konstruktor (Muster
 * EvaluationFieldDriftTest) — kein DB-Zugriff, kein Laravel-Bootstrap.
 */
class RecPersonFillableGuardTest extends TestCase
{
    private const NICHT_MASSENZUWEISBAR = ['phone', 'password_hash', 'registered_at', 'merged_into_person_id'];

    public function test_die_vier_spalten_sind_nicht_massenzuweisbar(): void
    {
        $rc = new ReflectionClass(RecPerson::class);
        $p = $rc->getProperty('fillable');
        $p->setAccessible(true);
        /** @var list<string> $fillable */
        $fillable = $p->getValue($rc->newInstanceWithoutConstructor());

        foreach (self::NICHT_MASSENZUWEISBAR as $spalte) {
            $this->assertNotContains(
                $spalte,
                $fillable,
                "rec_persons.{$spalte} darf nicht in \$fillable stehen — sonst schreibt ein "
                .'RecPerson::update([...]) an PersonLinker vorbei.',
            );
        }
    }
}
