<?php

namespace Platform\Recruiting\Services;

final class ContractSendRunResult
{
    /** @var list<int> */ public array $versendet = [];
    /** @var list<int> */ public array $vorgemerkt = [];
    /** @var array<int, string> */ public array $gesperrt = [];
    /** @var array<int, string> */ public array $fehler = [];

    public function meldung(): string
    {
        $teile = [count($this->versendet) . ' versendet'];
        if ($this->vorgemerkt !== []) {
            $teile[] = count($this->vorgemerkt) . ' vorgemerkt (Daten fehlen — Erinnerung geschickt, Versand folgt automatisch)';
        }
        if ($this->gesperrt !== []) {
            $teile[] = count($this->gesperrt) . ' gesperrt (siehe Teilnehmerliste)';
        }
        if ($this->fehler !== []) {
            $teile[] = count($this->fehler) . ' mit Fehler: ' . implode(' · ', array_slice(array_values($this->fehler), 0, 3));
        }

        return implode(', ', $teile) . '.';
    }

    public function hatFehler(): bool
    {
        return $this->fehler !== [];
    }
}
