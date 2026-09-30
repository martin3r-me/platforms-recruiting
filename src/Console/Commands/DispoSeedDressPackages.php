<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecDispoDressPackage;

/**
 * Legt den Startbestand der Waeschepakete an (Liste Markus Ammerer,
 * 29.09.2026).
 *
 * Zwei bewusste Eingriffe in die gelieferte Liste:
 *   - Trenner vereinheitlicht auf Semikolon (geliefert war Komma/Semikolon
 *     gemischt). Inhalte unveraendert.
 *   - "Standard schwarz-schwarz" nannte die schwarze Schuerze zweimal —
 *     Tippfehler, vom Kunden am 30.09. bestaetigt.
 *
 * Idempotent ueber den Namen: ein zweiter Lauf legt nichts erneut an und
 * ueberschreibt nichts (spaetere Pflege passiert in der Maske).
 */
class DispoSeedDressPackages extends Command
{
    protected $signature = 'recruiting:dispo-seed-dress-packages {--dry-run : nur zeigen, was angelegt wuerde}';

    protected $description = 'Legt den Startbestand der Waeschepakete an (idempotent)';

    /** @var list<array{name:string, items:string}> */
    public const PACKAGES = [
        ['name' => 'Standard schwarz-weiß', 'items' => 'weißes Hemd; schwarze Hose; schwarze Schürze; schwarze Socken; schwarze Schuhe'],
        ['name' => 'Standard schwarz-schwarz', 'items' => 'schwarzes Hemd; schwarze Hose; schwarze Schürze; schwarze Socken; schwarze Schuhe'],
        ['name' => 'Logistik', 'items' => 'schwarzer Hoodie; schwarzer Pulli; schwarzes T-Shirt; schwarze Hose; Sicherheitsschuhe; keine Jogginghose'],
        ['name' => 'Jeans Outfit', 'items' => 'weißes Hemd; Jeans Hose (ohne Löcher); saubere schwarze oder weiße Sneaker; schwarze Schürze'],
        ['name' => 'Spieltag Borussia Mönchengladbach', 'items' => 'schwarze Hose; schwarze Schuhe oder Pumas'],
        ['name' => 'Borussia Mönchengladbach Sonderveranstaltung', 'items' => 'weißes Hemd; schwarze Hose; schwarze Schürze; schwarze Socken; schwarze Schuhe'],
        ['name' => 'Catering Borussia MGL Spieltag', 'items' => 'blaue Jeans (ohne Löcher); weißes Hemd/Bluse; schwarze Schuhe oder Pumas'],
        ['name' => 'Bonn Broich', 'items' => 'schwarze Stoffhose; schwarze Schuhe; schwarze Socken; weißes Hemd/Bluse'],
        ['name' => 'Aufbaukleidung Bonn', 'items' => 'zivil; dunkles Oberteil; feste Schuhe'],
        ['name' => 'Messe DUS-Hallen', 'items' => 'Hemd via Kunden; schwarze Hose; schwarze Schuhe; Schürze via Kunden'],
        ['name' => 'Lanxess Arena', 'items' => 'schwarze Stoffhose; schwarze Schuhe; schwarze Socken; Oberbekleidung vom Kunden'],
    ];

    /** @return int Anzahl neu angelegter Pakete */
    public static function seed(int $teamId): int
    {
        $created = 0;
        foreach (self::PACKAGES as $i => $package) {
            $exists = RecDispoDressPackage::query()
                ->where('team_id', $teamId)
                ->where('name', $package['name'])
                ->exists();
            if ($exists) {
                continue;
            }

            RecDispoDressPackage::create([
                'team_id'    => $teamId,
                'name'       => $package['name'],
                'items_text' => $package['items'],
                'sort_order' => $i,
            ]);
            $created++;
        }

        return $created;
    }

    public function handle(): int
    {
        $teamId = (int) config('recruiting.zas.inbound_team_id');
        if ($teamId === 0) {
            $this->error('recruiting.zas.inbound_team_id ist nicht gesetzt.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            foreach (self::PACKAGES as $package) {
                $this->line($package['name'] . ' — ' . $package['items']);
            }
            $this->info(count(self::PACKAGES) . ' Pakete wuerden geprueft (Team ' . $teamId . ').');

            return self::SUCCESS;
        }

        $this->info(self::seed($teamId) . ' Pakete angelegt (Team ' . $teamId . ').');

        return self::SUCCESS;
    }
}
