<?php

namespace Platform\Recruiting\Tests\Unit;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\InterviewBookingOrder;

/**
 * Reihenfolge der Schulungen im Bewerber-Panel: neueste zuerst.
 *
 * Sortiert wird ueber EINEN Callback mit zusammengesetztem Schluessel. Die
 * mehrspaltige sortBy-Form mit Closures ist in Laravel ein stilles No-op —
 * die Liste kaeme unsortiert zurueck, ohne dass irgendetwas meckert.
 *
 * Der Sorter liest nur `->interview?->starts_at` und `->id`, deshalb genuegen
 * hier einfache Attrappen: ein Eloquent-Modell mit Relation aufzubauen wuerde
 * den Boot-Cache anfassen, ohne die Sortierung besser zu pruefen.
 */
class InterviewBookingOrderTest extends TestCase
{
    private function booking(int $id, ?string $startsAt): object
    {
        return new class($id, $startsAt) {
            public int $id;
            public ?object $interview;

            public function __construct(int $id, ?string $startsAt)
            {
                $this->id = $id;
                $this->interview = $startsAt === null ? null : new class($startsAt) {
                    public \DateTimeImmutable $starts_at;
                    public function __construct(string $startsAt)
                    {
                        $this->starts_at = new \DateTimeImmutable($startsAt);
                    }
                };
            }
        };
    }

    /** @param list<object> $bookings */
    private function idsAfterSort(array $bookings): array
    {
        return InterviewBookingOrder::newestFirst(new Collection($bookings))
            ->map(fn ($b) => $b->id)
            ->all();
    }

    public function test_neuere_schulung_steht_vor_aelterer(): void
    {
        $alt  = $this->booking(1, '2026-08-12 18:00:00');
        $neu  = $this->booking(2, '2026-08-26 18:00:00');

        $this->assertSame([2, 1], $this->idsAfterSort([$alt, $neu]));
        $this->assertSame([2, 1], $this->idsAfterSort([$neu, $alt]), 'Reihenfolge der Eingabe darf egal sein.');
    }

    public function test_buchung_ohne_termin_landet_am_ende(): void
    {
        $ohneTermin = $this->booking(9, null);
        $mitTermin  = $this->booking(1, '2026-01-01 09:00:00');

        $this->assertSame([1, 9], $this->idsAfterSort([$ohneTermin, $mitTermin]));
    }

    public function test_gleicher_termin_neuere_buchung_zuerst(): void
    {
        $frueher = $this->booking(10, '2026-08-26 18:00:00');
        $spaeter = $this->booking(11, '2026-08-26 18:00:00');

        $this->assertSame([11, 10], $this->idsAfterSort([$frueher, $spaeter]));
    }

    public function test_leere_liste_bleibt_leer(): void
    {
        $this->assertSame([], $this->idsAfterSort([]));
    }
}
