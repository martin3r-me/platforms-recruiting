{{-- Eskalations-Felder (Runde 3, #5): native Inputs, String-Props. Wird im Sende-Modal
     (eingeklappt) und im Anpassen-Dialog gerendert. Erwartet $defaults [1=>,2=>,3=>]. --}}
<div class="space-y-2 text-sm">
    <div class="flex flex-wrap gap-4">
        <label class="flex items-center gap-2">
            <input type="radio" wire:model="escDay" value="vortag" class="border-gray-300"> Vortag
        </label>
        <label class="flex items-center gap-2">
            <input type="radio" wire:model="escDay" value="einsatztag" class="border-gray-300"> Einsatztag
        </label>
    </div>
    <div class="grid grid-cols-3 gap-2">
        <label class="block text-xs text-gray-600">Stufe 1 (Reminder)
            <input type="time" wire:model="escTime1" placeholder="{{ $defaults[1] }}" class="mt-1 w-full rounded border border-gray-300 px-2 py-1 text-sm">
        </label>
        <label class="block text-xs text-gray-600">Stufe 2 (final)
            <input type="time" wire:model="escTime2" placeholder="{{ $defaults[2] }}" class="mt-1 w-full rounded border border-gray-300 px-2 py-1 text-sm">
        </label>
        <label class="block text-xs text-gray-600">Stufe 3 (Rausnahme)
            <input type="time" wire:model="escTime3" placeholder="{{ $defaults[3] }}" class="mt-1 w-full rounded border border-gray-300 px-2 py-1 text-sm">
        </label>
    </div>
    <div class="flex items-center justify-between text-xs text-gray-500">
        <span>Leer = Standard ({{ $defaults[1] }} / {{ $defaults[2] }} / {{ $defaults[3] }}). Am Einsatztag müssen alle Stufen vor Schichtbeginn liegen.</span>
        <button type="button" wire:click="resetEscalation" class="text-blue-600 hover:underline">Standard verwenden</button>
    </div>
    @error('escTime1') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
</div>
