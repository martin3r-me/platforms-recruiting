<div class="max-w-3xl mx-auto p-6 space-y-6">
    <div>
        <h1 class="text-xl font-semibold text-gray-900">MA-Kontaktbuch</h1>
        <p class="text-[13px] text-gray-500 mt-1">
            Eine automatisch verwaltete CRM-Kontaktliste mit genau den <strong>aktiven Mitarbeitern</strong> —
            als CardDAV-Telefonbuch abonnierbar (CRM &rarr; Kontaktliste &rarr; Tab „Adressbuch").
        </p>
    </div>

    @if (session('message'))
        <div class="rounded-md bg-green-50 border border-green-200 px-4 py-3 text-[13px] text-green-800">
            {{ session('message') }}
        </div>
    @endif

    @if (session('warning'))
        <div class="rounded-md bg-amber-50 border border-amber-300 px-4 py-3 text-[13px] text-amber-900">
            {{ session('warning') }}
        </div>
    @endif

    @if (!$this->list)
        <section class="bg-white rounded-lg border border-gray-200 p-4 space-y-3">
            <p class="text-[13px] text-gray-600">
                Für dieses Team ist noch kein Kontaktbuch konfiguriert. Beim Anlegen wird eine
                CRM-Kontaktliste „Aktive Mitarbeiter" erstellt und initial befüllt.
            </p>
            <button type="button" wire:click="createList"
                class="px-4 py-2 text-[13px] font-medium rounded-md bg-[#ff7a59] text-white hover:bg-[#ff6a45] transition-colors">
                MA-Kontaktbuch anlegen
            </button>
        </section>
    @elseif (!$this->list->is_active)
        <section class="bg-white rounded-lg border border-red-200 p-4 space-y-3">
            <p class="text-[13px] text-red-700">
                Die konfigurierte Liste ist inaktiv oder wurde ersetzt. Bitte neu anlegen.
            </p>
            <button type="button" wire:click="createList"
                class="px-4 py-2 text-[13px] font-medium rounded-md bg-red-600 text-white hover:bg-red-700 transition-colors">
                Neu anlegen
            </button>
        </section>
    @else
        <section class="bg-white rounded-lg border border-gray-200 p-4 space-y-4">
            <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 text-[11px] font-medium rounded bg-amber-100 text-amber-800">sync-verwaltet</span>
                <span class="text-sm font-semibold text-gray-900">{{ $this->list->name }}</span>
            </div>

            <dl class="grid grid-cols-2 gap-3 text-[13px]">
                <div><dt class="text-gray-400">Mitglieder</dt><dd class="text-gray-900 font-medium">{{ $this->list->member_count }}</dd></div>
                <div><dt class="text-gray-400">Letzter Sync</dt><dd class="text-gray-900 font-medium">{{ $this->lastSync ?? '—' }}</dd></div>
            </dl>

            <p class="text-[11px] text-gray-400">
                Manuelle Änderungen an der Liste werden beim nächsten Sync überschrieben.
                Mitarbeiter ohne CRM-Kontakt fehlen, bis der Kontakt verlinkt ist
                (<code>recruiting:zas-crm-contact-backfill</code>).
            </p>

            @if ($pendingDryRun)
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 space-y-3">
                    <p class="text-[13px] font-medium text-amber-900">
                        Sync würde <strong>{{ $pendingDryRun['report']['removed'] }}</strong> entfernen,
                        <strong>{{ $pendingDryRun['report']['added'] }}</strong> hinzufügen,
                        {{ $pendingDryRun['report']['normalized'] }} renormalisieren
                        ({{ $pendingDryRun['report']['skipped_without_contact'] }} ohne Kontakt,
                        {{ $pendingDryRun['report']['hidden_from_carddav'] }} nicht auslieferbar,
                        {{ $pendingDryRun['report']['ambiguous_multi_link'] }} mit Mehrfach-Link).
                        @if ($pendingDryRun['guard_reason'] === 'empty_soll')
                            <br><strong>Gestoppt:</strong> keine auslieferbaren Kontakte gefunden — deutet auf
                            fehlende CRM-Links hin. Nicht übersteuerbar; ggf.
                            <code>recruiting:zas-crm-contact-backfill</code> laufen lassen oder die Liste
                            manuell über die CRM-Listen-UI pflegen.
                        @elseif ($pendingDryRun['guard_reason'] === 'threshold')
                            <br><strong>Schutz ausgelöst</strong> — „Ausführen" übersteuert die Entfernungs-Schwelle.
                        @endif
                        @if ($pendingDryRun['guard_reason'] !== 'empty_soll')
                            — ausführen?
                        @endif
                    </p>
                    <div class="flex gap-2">
                        @if ($pendingDryRun['guard_reason'] !== 'empty_soll')
                            <button type="button" wire:click="confirmSync"
                                class="px-4 py-2 text-[13px] font-medium rounded-md bg-amber-600 text-white hover:bg-amber-700 transition-colors">
                                Ausführen
                            </button>
                        @endif
                        <button type="button" wire:click="cancelSync"
                            class="px-4 py-2 text-[13px] font-medium rounded-md border border-gray-300 text-gray-600 hover:bg-gray-50 transition-colors">
                            Abbrechen
                        </button>
                    </div>
                </div>
            @else
                <button type="button" wire:click="startSync"
                    class="px-4 py-2 text-[13px] font-medium rounded-md bg-[#ff7a59] text-white hover:bg-[#ff6a45] transition-colors">
                    Jetzt synchronisieren
                </button>
            @endif
        </section>
    @endif
</div>
