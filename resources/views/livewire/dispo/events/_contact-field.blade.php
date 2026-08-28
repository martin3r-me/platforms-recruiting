{{-- Ansprechpartner-Feld (Runde-3-Nachzug): Teamleitung ist Standard, manuell ueberschreibbar.
     Wird im Sende-Modal und im Anpassen-Dialog gerendert. Erwartet $leads (list) — die
     Komponenten-Props $ansprechpartner / $contactSource / $leadChoice sind im Blade verfuegbar. --}}
<input type="text" wire:model="ansprechpartner" placeholder="z. B. Sheran (0170 1234567)"
       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
<span class="mt-1 block text-xs text-gray-500">Erscheint auf der Einsatz-Seite als „Dein Ansprechpartner ist …". Standard ist die disponierte Teamleitung — zieht bei Änderungen in ZAS automatisch mit.</span>
@if ($leads === [])
    <span class="mt-1 block text-xs text-gray-500">Keine Teamleitung disponiert — bitte manuell eintragen.</span>
@else
    @if ($contactSource === 'manual')
        <span class="mt-1 block text-xs text-amber-700">
            Manuell überschrieben. Standard wäre Teamleitung {{ $leads[0]['label'] }}
            <button type="button" wire:click="useLeadDefault" class="ml-1 text-blue-600 hover:underline">Standard verwenden</button>
        </span>
    @else
        <span class="mt-1 block text-xs text-green-700">Standard: Teamleitung {{ $leads[0]['label'] }} — bei Bedarf einfach überschreiben.</span>
    @endif
    @if (count($leads) > 1)
        <div class="mt-1 flex items-center gap-2 text-xs text-gray-600">
            <span>Andere Teamleitung:</span>
            <select wire:model="leadChoice" class="rounded border border-gray-300 px-2 py-1 text-xs">
                @foreach ($leads as $lead)
                    <option value="{{ $lead['employee_id'] }}">{{ $lead['label'] }}</option>
                @endforeach
            </select>
            <button type="button" wire:click="applyLead" class="text-blue-600 hover:underline">übernehmen</button>
        </div>
    @endif
    @if ($leads[0]['phone'] === null)
        <span class="mt-1 block text-xs text-amber-700">Teamleitung ohne Handynummer im Datensatz.</span>
    @endif
@endif
@error('ansprechpartner') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
