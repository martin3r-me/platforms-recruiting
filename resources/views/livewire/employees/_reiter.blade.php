{{-- Reiter des Mitarbeiter-Bereichs (Mitarbeiter / Dokumente / Lohnaenderungen),
     ersetzt die beiden Seitenleisten-Eintraege. Aufruf mit ['reiter' => 'mitarbeiter'|'dokumente'|'lohn']. --}}
@php
    $reiterAktuell = $reiter ?? 'mitarbeiter';
    $reiterListe = [
        'mitarbeiter' => ['label' => 'Mitarbeiter', 'href' => route('recruiting.employees.index')],
        'dokumente'   => ['label' => 'Dokumente', 'href' => route('recruiting.employees.documents')],
        'lohn'        => ['label' => 'Lohnänderungen', 'href' => route('recruiting.employees.payroll-changes')],
    ];
    $reiterAktivKlasse = 'border-blue-600 text-blue-700';
    $reiterInaktivKlasse = 'border-transparent text-gray-500 hover:text-gray-700';
@endphp
<nav class="mb-4 flex items-center gap-1 border-b border-gray-200" aria-label="Mitarbeiter-Bereich">
    @foreach ($reiterListe as $reiterSchluessel => $reiterEintrag)
        @php
            $reiterIstAktiv = $reiterSchluessel === $reiterAktuell;
            $reiterKlasse = $reiterIstAktiv ? $reiterAktivKlasse : $reiterInaktivKlasse;
            $reiterAria = $reiterIstAktiv ? 'page' : 'false';
        @endphp
        <a href="{{ $reiterEintrag['href'] }}" wire:navigate data-reiter="{{ $reiterSchluessel }}" aria-current="{{ $reiterAria }}"
           class="-mb-px border-b-2 px-3 py-2 text-sm font-medium {{ $reiterKlasse }}">{{ $reiterEintrag['label'] }}</a>
    @endforeach
</nav>
