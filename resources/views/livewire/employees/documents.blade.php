@php
    // PDF-Weg: Text-Kategorien (info) gibt es nur ueber den Umschalter PDF | Info.
    $kategorien = \Platform\Recruiting\Support\DokumentKategorie::labelsFuerDatei();
    $istInfo = $art === 'info';
    $artAn = 'bg-blue-50 text-blue-800 font-medium';
    $artAus = 'text-[var(--ui-muted)] hover:bg-[var(--ui-muted-5)]';
    $artPdfKlasse = $istInfo ? $artAus : $artAn;
    $artInfoKlasse = $istInfo ? $artAn : $artAus;
    $aktionen = \Platform\Recruiting\Support\DokumentKategorie::aktionen();
    $kandidaten = $this->kandidaten;
    $abgeschnitten = $this->abgeschnitten;
    // Opt-in: nur die angehakten Personen, serverseitig gegen das Team geprueft.
    $auswahlZeilen = $this->ausgewaehltZeilen;
    $auswahlIds = array_column($auswahlZeilen, 'id');
    $gewaehlt = count($auswahlZeilen);
    $ohnePortal = count(array_filter($auswahlZeilen, fn ($k) => !$k['hat_portal']));
    // Nur wer im neuen Portal ist, bekommt die WhatsApp (Review Task 5, M2: zwei Aussagen statt "davon … ohne").
    $mitWhatsApp = max(0, $gewaehlt - $ohnePortal);
    $trefferIds = array_column($kandidaten, 'id');
    $trefferGewaehlt = count(array_intersect($trefferIds, $auswahlIds));
    $alleTrefferGewaehlt = count($trefferIds) > 0 && $trefferGewaehlt === count($trefferIds);
    $kopfTeilweise = $trefferGewaehlt > 0 && !$alleTrefferGewaehlt;
    $kopfAktion = $alleTrefferGewaehlt ? 'alleTrefferAb' : 'alleTrefferAn';
    $kopfGesperrt = count($trefferIds) === 0 || ($abgeschnitten && !$alleTrefferGewaehlt);
    $kopfZustand = $alleTrefferGewaehlt ? 'an' : ($kopfTeilweise ? 'teil' : 'aus');
    $kopfTeilweiseJs = $kopfTeilweise ? 'true' : 'false';
    $sendenGesperrt = $gewaehlt === 0;
    // Eine Info mit Automatik-Haken darf ohne Empfaenger raus (wartet auf Neue).
    if ($istInfo && $autoNeue) {
        $sendenGesperrt = false;
    }
    $aktionLabel = $aktionen[$aktion] ?? $aktion;
    $bestaetigungTitel = trim($titel);
    $dokumente = $this->dokumente;
    // Pollt nur, solange ein Versand juenger als die Versandfrist (30 min) unversucht ist.
    $versandLaeuft = count(array_filter($dokumente, fn ($d) => $d['versand_laeuft'])) > 0;
@endphp
<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Dokumente" icon="heroicon-o-document-text" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard'), 'icon' => 'briefcase'],
            ['label' => 'Mitarbeiter', 'href' => route('recruiting.employees.index')],
            ['label' => 'Dokumente'],
        ]">
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container width="full">
        @include('recruiting::livewire.employees._reiter', ['reiter' => 'dokumente'])

        @if ($flash)
            <div class="mb-4 p-3 bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-md">{{ $flash }}</div>
        @endif
        @if ($flashError)
            <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-800 text-sm rounded-md">{{ $flashError }}</div>
        @endif

        {{-- 1. Dokument --}}
        <div class="bg-white border border-[var(--ui-border)] rounded-lg p-4">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                <h3 class="text-sm font-semibold text-[var(--ui-secondary)]">1 · Dokument</h3>
                {{-- Umschalter PDF | Info (Spec 2026-10-16 §3.2) --}}
                <div class="inline-flex rounded-md border border-[var(--ui-border)] overflow-hidden text-sm" role="radiogroup" aria-label="Art">
                    <label class="flex items-center gap-1.5 px-3 py-1 cursor-pointer {{ $artPdfKlasse }}">
                        <input type="radio" name="dokument-art" value="pdf" wire:model.live="art" class="sr-only">
                        PDF
                    </label>
                    <label class="flex items-center gap-1.5 px-3 py-1 cursor-pointer border-l border-[var(--ui-border)] {{ $artInfoKlasse }}">
                        <input type="radio" name="dokument-art" value="info" wire:model.live="art" class="sr-only">
                        Info
                    </label>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                @if (!$istInfo)
                <div class="md:col-span-4">
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">PDF bis 12 MB</label>
                    <input type="file" accept=".pdf" wire:model="datei" class="block w-full text-sm text-gray-600 file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100">
                    <div wire:loading wire:target="datei" class="text-xs text-[var(--ui-muted)] mt-1">Wird hochgeladen …</div>
                    @error('datei')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>
                @endif
                <div class="md:col-span-4">
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Titel</label>
                    <input type="text" wire:model="titel" class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm">
                </div>
                @if (!$istInfo)
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Kategorie</label>
                    <select wire:model.live="kategorie" class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                        @foreach ($kategorien as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Der Mitarbeiter soll</label>
                    <select wire:model="aktion" class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                        @foreach ($aktionen as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
            </div>

            @if ($istInfo)
                {{--
                    Editor: Toast UI aus der Host-App (window.ToastUIEditor, Muster platform-notes boot()
                    mit Rueckfall auf toastui:ready). wire:ignore ist Pflicht, sonst raeumt Livewire den
                    Editor beim naechsten Rerender ab. Fehlt der Editor, bleibt das Textfeld (Markdown).
                    Nach dem Bereitstellen leert das Event info-bereitgestellt beide.
                --}}
                <div class="mt-3" wire:key="info-editor" wire:ignore
                     x-data="{
                        bereit: false,
                        editor: null,
                        timer: null,
                        init() {
                            if (!this.boot()) {
                                window.addEventListener('toastui:ready', () => this.boot(), { once: true });
                            }
                        },
                        boot() {
                            const Editor = window.ToastUIEditor;
                            if (!Editor || this.editor) return !!this.editor;
                            this.bereit = true;
                            try {
                                this.editor = new Editor({
                                    el: this.$refs.editorEl,
                                    height: '360px',
                                    initialEditType: 'wysiwyg',
                                    hideModeSwitch: true,
                                    usageStatistics: false,
                                    placeholder: 'Text der Info …',
                                    toolbarItems: [['heading', 'bold', 'italic'], ['ul', 'ol'], ['link']],
                                    initialValue: this.$wire.infoMarkdown || '',
                                });
                            } catch (e) {
                                this.bereit = false;
                                this.editor = null;
                                return false;
                            }
                            this.editor.on('blur', () => this.uebernehmen());
                            this.editor.on('change', () => {
                                clearTimeout(this.timer);
                                this.timer = setTimeout(() => this.uebernehmen(), 400);
                            });
                            return true;
                        },
                        uebernehmen() {
                            clearTimeout(this.timer);
                            if (this.editor) this.$wire.set('infoMarkdown', this.editor.getMarkdown(), false);
                        },
                        leeren() {
                            clearTimeout(this.timer);
                            if (this.editor) this.editor.setMarkdown('');
                            if (this.$refs.fallback) this.$refs.fallback.value = '';
                        },
                        destroy() {
                            clearTimeout(this.timer);
                            if (this.editor && typeof this.editor.destroy === 'function') this.editor.destroy();
                        },
                     }"
                     x-on:info-bereitgestellt.window="leeren()">
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Text der Info</label>
                    <div x-show="!bereit">
                        <textarea x-ref="fallback" wire:model.blur="infoMarkdown" rows="12" class="w-full border border-[var(--ui-border)] rounded-md px-3 py-2 text-sm font-mono" placeholder="Text (Markdown: # Überschrift, **fett**, - Liste, [Link](https://…))"></textarea>
                        <p class="text-xs text-[var(--ui-muted)] mt-1">Markdown: # Überschrift, **fett**, *kursiv*, - Liste, [Linktext](https://…). Telefonnummern werden im Portal automatisch zum Anrufknopf.</p>
                    </div>
                    <div x-show="bereit" x-ref="editorEl"></div>
                </div>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input type="checkbox" wire:model.live="autoNeue">
                        Automatisch an neue Mitarbeiter (beim ersten Öffnen des Portals)
                    </label>
                    <button type="button" wire:click="$set('vorschauOffen', true)" class="text-xs text-blue-700 hover:underline">Vorschau wie im Portal</button>
                </div>
            @endif
        </div>

        {{-- 2. Empfaenger --}}
        <div class="mt-4 bg-white border border-[var(--ui-border)] rounded-lg p-4">
            <h3 class="text-sm font-semibold text-[var(--ui-secondary)] mb-3">2 · Empfänger</h3>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                {{-- Links: Treffer der Suche, je Person ein Haken --}}
                <div>
                    <div class="flex flex-wrap items-end gap-3 mb-3">
                        <div class="flex-1 min-w-[200px]">
                            <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Suche</label>
                            <input type="text" wire:model.live.debounce.300ms="suche" placeholder="Name oder Personalnummer" class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Firma</label>
                            <select wire:model.live="firma" class="border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                                <option value="">Alle</option>
                                <option value="RG">RG</option>
                                <option value="MA">MA</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Status</label>
                            <select wire:model.live="aktiv" class="border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                                <option value="active">Aktiv</option>
                                <option value="inactive">Inaktiv</option>
                                <option value="all">Alle</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Tätigkeit (ZAS, nur RG)</label>
                            <select wire:model.live="taetigkeit" class="border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                                <option value="">Alle</option>
                                @foreach ($this->taetigkeitOptionen as $t)
                                    <option value="{{ $t }}">{{ $t }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Veranstaltung</label>
                            <select wire:model.live="eventId" class="border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                                <option value="">Keine</option>
                                @foreach ($this->eventOptionen as $ev)
                                    <option value="{{ $ev['id'] }}">{{ $ev['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="flex items-center justify-between gap-3 px-1 mb-1">
                        <label wire:key="treffer-kopf-{{ $kopfZustand }}" class="flex items-center gap-2 text-sm font-medium cursor-pointer">
                            <input type="checkbox" data-test="alle-treffer" wire:click="{{ $kopfAktion }}" x-init="$el.indeterminate = {{ $kopfTeilweiseJs }}" @checked($alleTrefferGewaehlt) @disabled($kopfGesperrt)>
                            Alle Treffer auswählen
                        </label>
                        <span class="text-xs text-[var(--ui-muted)]">{{ count($kandidaten) }} Treffer · {{ $trefferGewaehlt }} davon ausgewählt</span>
                    </div>
                    @if ($abgeschnitten)
                        <div class="mb-1 px-1 text-xs font-medium text-red-700">Mehr als {{ \Platform\Recruiting\Services\DokumentEmpfaengerSuche::LIMIT }} Treffer, nur die ersten werden gezeigt — „Alle Treffer“ geht erst nach dem Eingrenzen (z. B. Firma, Tätigkeit oder Veranstaltung).</div>
                    @endif
                    <div class="max-h-96 overflow-y-auto border border-[var(--ui-border)] rounded-md divide-y divide-[var(--ui-border)]/60">
                        @forelse ($kandidaten as $k)
                            @php
                                $kAn = in_array($k['id'], $auswahlIds, true);
                                $kSchluessel = 'treffer-' . $k['id'] . '-' . ($kAn ? 'an' : 'aus');
                            @endphp
                            <label wire:key="{{ $kSchluessel }}" class="flex items-center gap-3 px-3 py-1.5 text-sm cursor-pointer hover:bg-[var(--ui-muted-5)]">
                                <input type="checkbox" wire:click="toggle({{ $k['id'] }})" @checked($kAn)>
                                <span class="flex-1">{{ $k['name'] }}</span>
                                <span class="text-xs text-[var(--ui-muted)]">{{ $k['personnel_number'] ?? '—' }} · {{ $k['company'] ?? '—' }}</span>
                                @if (!$k['hat_portal'])
                                    <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-amber-50 text-amber-800 border border-amber-200">altes Portal</span>
                                @endif
                            </label>
                        @empty
                            <div class="px-3 py-4 text-sm text-[var(--ui-muted)]">Keine Treffer.</div>
                        @endforelse
                    </div>
                </div>

                {{-- Rechts: wer das Dokument bekommt --}}
                <div>
                    <div class="flex items-center justify-between gap-3 px-1 mb-1">
                        <span class="text-sm font-medium">Ausgewählt ({{ $gewaehlt }})</span>
                        @if ($gewaehlt > 0)
                            <button type="button" wire:click="auswahlLeeren" class="text-xs text-[var(--ui-muted)] hover:text-red-700 hover:underline">Auswahl leeren</button>
                        @endif
                    </div>
                    @if ($ohnePortal > 0)
                        <div class="mb-1 px-1 text-xs text-amber-700">{{ $ohnePortal }} davon im alten Portal — bekommen keine WhatsApp</div>
                    @endif
                    <div class="max-h-96 overflow-y-auto border border-[var(--ui-border)] rounded-md divide-y divide-[var(--ui-border)]/60">
                        @forelse ($auswahlZeilen as $w)
                            <div wire:key="auswahl-{{ $w['id'] }}" class="flex items-center gap-3 px-3 py-1.5 text-sm">
                                <span class="flex-1">{{ $w['name'] }}</span>
                                <span class="text-xs text-[var(--ui-muted)]">{{ $w['personnel_number'] ?? '—' }} · {{ $w['company'] ?? '—' }}</span>
                                @if (!$w['hat_portal'])
                                    <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-amber-50 text-amber-800 border border-amber-200">altes Portal</span>
                                @endif
                                <button type="button" wire:click="entfernen({{ $w['id'] }})" class="text-[var(--ui-muted)] hover:text-red-700" title="Entfernen" aria-label="Entfernen">✕</button>
                            </div>
                        @empty
                            <div class="px-3 py-4 text-sm text-[var(--ui-muted)]">Noch niemand ausgewählt — links anhaken.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="mt-4 flex flex-col items-end gap-2">
                {{-- Haken "WhatsApp-Hinweis senden" (PDF und Info), vorausgewaehlt; aus = notify_mode none. --}}
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="checkbox" wire:model.live="whatsappHinweis">
                    WhatsApp-Hinweis senden
                </label>
                <button type="button" wire:click="bereitstellenPruefen" wire:loading.attr="disabled" wire:target="bereitstellenPruefen,bereitstellen,datei" @disabled($sendenGesperrt)
                        class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 disabled:opacity-60">
                    @if ($istInfo)
                        Info an {{ $gewaehlt }} Empfänger bereitstellen
                    @else
                        An {{ $gewaehlt }} Personen bereitstellen
                    @endif
                </button>
            </div>
        </div>

        {{-- 3. Ueberblick --}}
        <div class="mt-6">
            <div class="flex items-center justify-between mb-2">
                <h3 class="text-sm font-semibold text-[var(--ui-secondary)]">Unterwegs und erledigt</h3>
                <select wire:model.live="zeige" class="border border-[var(--ui-border)] rounded-md px-2 py-1 text-xs bg-white">
                    <option value="offen">Nur offene</option>
                    <option value="alle">Alle</option>
                </select>
            </div>
            <div class="bg-white border border-[var(--ui-border)] rounded-lg divide-y divide-[var(--ui-border)]/60" @if ($versandLaeuft) wire:poll.10s @endif>
                @forelse ($dokumente as $dok)
                    @php
                        $dokOffen = $aufgeklappt === $dok['id'];
                        $dokBalken = $dok['fortschritt']['gesamt'] > 0 ? (int) round(100 * $dok['fortschritt']['erledigt'] / $dok['fortschritt']['gesamt']) : 0;
                        $dokVersandZeile = $dok['benachrichtigt'] . ' von ' . ($dok['benachrichtigt'] + $dok['ausstehend']) . ' benachrichtigt, '
                            . ($dok['versand_laeuft'] ? 'läuft …' : 'Rest nicht gesendet');
                        $dokZeigtAnstossen = $dok['ausstehend'] > 0 && !$dok['geloescht'];
                        // Infos: "gesehen x von y" (Spec 2026-10-16 §6), sonst der Text aus DokumentFortschritt.
                        $dokFortschrittText = $dok['is_info']
                            ? 'gesehen ' . $dok['fortschritt']['erledigt'] . ' von ' . $dok['fortschritt']['gesamt']
                            : $dok['fortschritt']['text'];
                        $dokAutoText = 'automatisch an Neue seit ' . ($dok['published'] ?? '—');
                        // Infos haben keine Unterschrift: Zurueckziehen nimmt sie allen weg (auch Gesehenen) und beendet die Automatik.
                        $dokZurueckFrage = $dok['is_info']
                            ? '„' . $dok['title'] . '“ zurückziehen? Die Info verschwindet für alle aus dem Portal, die Automatik endet.'
                            : '„' . $dok['title'] . '“ für alle Offenen zurückziehen?';
                    @endphp
                    <div class="p-3">
                        <div class="flex items-center gap-3">
                            <button type="button" wire:click="aufklappen({{ $dok['id'] }})" class="flex-1 text-left">
                                <div class="text-sm font-medium">{{ $dok['title'] }} @if ($dok['geloescht'])<span class="text-xs text-[var(--ui-muted)]">(zurückgezogen)</span>@endif</div>
                                <div class="text-xs text-[var(--ui-muted)]">{{ $dok['category_label'] }} · {{ $dok['action_label'] }} · {{ $dok['erstellt'] }}@if ($dok['event']) · {{ $dok['event'] }}@endif</div>
                                @if ($dok['auto'])
                                    <div class="mt-1 flex items-center gap-2 flex-wrap">
                                        <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-blue-50 text-blue-800 border border-blue-200">{{ $dokAutoText }}</span>
                                        <span class="text-[10px] text-[var(--ui-muted)]">{{ $dok['automatisch'] }} automatisch zugestellt</span>
                                    </div>
                                @elseif ($dok['automatisch'] > 0)
                                    <div class="mt-1 text-[10px] text-[var(--ui-muted)]">{{ $dok['automatisch'] }} automatisch zugestellt · Automatik beendet</div>
                                @endif
                                @if ($dok['ohne_whatsapp'])
                                    <div class="mt-1">
                                        <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-gray-50 text-gray-700 border border-gray-200">ohne WhatsApp</span>
                                    </div>
                                @endif
                            </button>
                            <div class="w-44">
                                <div class="text-xs text-right mb-1">{{ $dokFortschrittText }}</div>
                                @if ($dok['ausstehend'] > 0)
                                    <div class="text-[10px] text-right text-amber-700">{{ $dokVersandZeile }}</div>
                                @elseif ($dok['braucht_hinweis'])
                                    <div class="text-[10px] text-right text-[var(--ui-muted)]">{{ $dok['benachrichtigt'] }} benachrichtigt</div>
                                @endif
                                <div class="h-1.5 bg-[var(--ui-muted-5)] rounded-full overflow-hidden"><div class="h-full bg-emerald-500" style="width: {{ $dokBalken }}%"></div></div>
                            </div>
                            @if ($dokZeigtAnstossen)
                                <button type="button" wire:click="versandAnstossen({{ $dok['id'] }})" class="px-3 py-1.5 border border-blue-300 text-xs rounded-md text-blue-800 bg-blue-50 hover:bg-blue-100">Versand erneut anstoßen</button>
                            @endif
                            {{-- Bewusste HR-Handlung: dreht "ohne WhatsApp" um und startet den Versand. --}}
                            @if ($dok['kann_whatsapp_jetzt'])
                                <button type="button" wire:click="whatsappJetztSenden({{ $dok['id'] }})" wire:confirm="Für „{{ $dok['title'] }}“ jetzt die WhatsApp an alle Offenen senden?" class="px-3 py-1.5 border border-blue-300 text-xs rounded-md text-blue-800 bg-blue-50 hover:bg-blue-100">WhatsApp jetzt senden</button>
                            @endif
                            @if ($dok['is_info'])
                                <span class="px-3 py-1.5 border border-[var(--ui-border)] text-xs rounded-md text-[var(--ui-muted)]">INFO</span>
                            @endif
                            @if (!$dok['is_info'])
                                <a href="{{ route('recruiting.employees.dokument.datei', ['uuid' => $dok['uuid']]) }}" target="_blank" class="px-3 py-1.5 border border-[var(--ui-border)] text-xs rounded-md bg-white hover:bg-[var(--ui-muted-5)]">PDF</a>
                            @endif
                            {{-- Stoppt nur die Zustellung an kuenftige Neue; Zurueckziehen nimmt die Info dagegen allen weg. --}}
                            @if ($dok['auto'])
                                <button type="button" wire:click="automatikBeenden({{ $dok['id'] }})" wire:confirm="Automatik für „{{ $dok['title'] }}“ beenden? Neue bekommen die Info dann nicht mehr, wer sie schon hat, behält sie." class="px-3 py-1.5 border border-blue-300 text-xs rounded-md text-blue-800 bg-blue-50 hover:bg-blue-100">Automatik beenden</button>
                            @endif
                            @if ($dok['zurueckziehbar'])
                                <button type="button" wire:click="dokumentZurueckziehen({{ $dok['id'] }})" wire:confirm="{{ $dokZurueckFrage }}" class="px-3 py-1.5 border border-[var(--ui-border)] text-xs rounded-md text-[var(--ui-muted)] hover:bg-red-50 hover:text-red-700">Zurückziehen</button>
                            @endif
                        </div>
                        @if ($dokOffen)
                            <div class="mt-3 border-t border-[var(--ui-border)]/60 pt-2 space-y-1">
                                @foreach ($dok['empfaenger'] as $em)
                                    @php
                                        $emVersand = $em['versand_text'];
                                        $emZeigtErneut = $em['kann_erneut_senden'];
                                    @endphp
                                    <div class="flex items-center gap-3 text-sm">
                                        <a href="{{ route('recruiting.employees.show', $em['employee_id']) }}" wire:navigate class="flex-1 hover:underline">{{ $em['name'] }}</a>
                                        <span class="text-xs">{{ $em['status_label'] }}</span>
                                        <span class="text-xs text-[var(--ui-muted)] w-44">{{ $emVersand }}</span>
                                        @if ($em['hat_nachweis'])
                                            <a href="{{ route('recruiting.employees.dokument.nachweis', ['uuid' => $em['recipient_uuid']]) }}" target="_blank" class="text-xs text-emerald-700 hover:underline">Nachweis</a>
                                        @endif
                                        @if ($emZeigtErneut)
                                            <button type="button" wire:click="erneutSenden({{ $em['recipient_id'] }})" class="text-xs text-blue-700 hover:underline">Erneut senden</button>
                                        @endif
                                        @if ($em['kann_zurueckziehen'])
                                            <button type="button" wire:click="zurueckziehen({{ $em['recipient_id'] }})" class="text-xs text-[var(--ui-muted)] hover:text-red-700">Zurückziehen</button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="p-6 text-center text-sm text-[var(--ui-muted)]">Nichts offen.</div>
                @endforelse
            </div>
        </div>
    </x-ui-page-container>

    <x-ui-modal wire:model="bestaetigungOffen" size="sm">
        <x-slot name="header">Bereitstellen bestätigen</x-slot>
        <div class="space-y-2 text-sm">
            @if ($istInfo)
                @if ($gewaehlt > 0 && !$whatsappHinweis)
                    <p>Info „{{ $bestaetigungTitel }}“ an {{ $gewaehlt }} Empfänger bereitstellen, ohne WhatsApp?</p>
                    <p>Keine Nachricht — die Info steht beim nächsten Öffnen im Portal.</p>
                    @if ($autoNeue)
                        <p>Außerdem automatisch an alle Neuen ab heute (beim ersten Öffnen des Portals).</p>
                    @endif
                @elseif ($gewaehlt > 0)
                    <p>Info „{{ $bestaetigungTitel }}“ an {{ $gewaehlt }} Empfänger bereitstellen?</p>
                    <p>WhatsApp geht an {{ $mitWhatsApp }} Empfänger.</p>
                    @if ($ohnePortal > 0)
                        <p class="text-amber-700">{{ $ohnePortal }} im alten Portal: keine Nachricht, sichtbar nach der Umstellung.</p>
                    @endif
                    @if ($autoNeue)
                        <p>Außerdem automatisch an alle Neuen ab heute (beim ersten Öffnen des Portals).</p>
                    @endif
                @else
                    <p>Info „{{ $bestaetigungTitel }}“: 0 Empfänger jetzt, automatisch an alle Neuen ab heute.</p>
                    <p>Keine WhatsApp — wer neu dazukommt, sieht die Info beim ersten Öffnen des Portals.</p>
                @endif
            @else
                <p>„{{ $bestaetigungTitel }}“ an {{ $gewaehlt }} Personen bereitstellen?</p>
                @if (!$whatsappHinweis)
                    <p>Ohne WhatsApp — das Dokument steht beim nächsten Öffnen im Portal.</p>
                @elseif ($ohnePortal > 0)
                    <p class="text-amber-700">{{ $ohnePortal }} davon im alten Portal (keine WhatsApp).</p>
                @endif
                <p>Der Mitarbeiter soll: {{ $aktionLabel }}.</p>
            @endif
        </div>
        <x-slot name="footer">
            <div class="flex justify-end gap-2">
                <x-ui-button type="button" variant="secondary-outline" wire:click="$set('bestaetigungOffen', false)">Abbrechen</x-ui-button>
                <x-ui-button type="button" variant="primary" wire:click="bereitstellen" wire:loading.attr="disabled" wire:target="bereitstellen">Bereitstellen</x-ui-button>
            </div>
        </x-slot>
    </x-ui-modal>

    {{-- Vorschau = Portal-Renderer (InfoText::html, Spec §5.3); die Regeln unten wiederholen portal-styles (.info-text). --}}
    <x-ui-modal wire:model="vorschauOffen" size="md">
        <x-slot name="header">Vorschau wie im Portal</x-slot>
        <style>
            .info-vorschau{--brand:#22a8b8; --brand-mid:#167582; --ink:#111827; --line:#e5e7eb; --line-2:#d1d5db; --surface:#ffffff; --r:8px; max-width:420px; margin:0 auto; border:1px solid var(--line); border-left:3px solid var(--brand); border-radius:10px; padding:14px 13px; background:var(--surface)}
            .info-vorschau .info-titel{font-size:17px; font-weight:600; line-height:1.3; margin:0 0 8px}
            .info-vorschau .info-text{font-size:14px; line-height:1.55; color:var(--ink)}
            .info-vorschau .info-text h2{font-size:16px; font-weight:700; line-height:1.3; margin:14px 0 6px}
            .info-vorschau .info-text h3{font-size:14.5px; font-weight:700; line-height:1.3; margin:12px 0 5px}
            .info-vorschau .info-text h2:first-child, .info-vorschau .info-text h3:first-child{margin-top:0}
            .info-vorschau .info-text p{margin:0 0 10px}
            .info-vorschau .info-text ul, .info-vorschau .info-text ol{margin:0 0 10px; padding-left:20px}
            .info-vorschau .info-text ul{list-style:disc}
            .info-vorschau .info-text ol{list-style:decimal}
            .info-vorschau .info-text li{margin:3px 0}
            .info-vorschau .info-text hr{border:none; border-top:1px solid var(--line); margin:12px 0}
            .info-vorschau .info-text > :last-child{margin-bottom:0}
            .info-vorschau .info-link, .info-vorschau .info-tel{display:inline-flex; align-items:center; justify-content:center; gap:6px; min-height:40px; padding:9px 14px; margin:4px 0; border-radius:var(--r); font-size:14px; font-weight:600; text-decoration:none}
            .info-vorschau .info-link{background:var(--brand); color:#fff; border:1px solid var(--brand)}
            .info-vorschau .info-tel{background:var(--surface); color:var(--brand-mid); border:1px solid var(--line-2)}
            .info-vorschau .info-tel::before{content:"\260E"}
        </style>
        <div class="info-vorschau">
            <h2 class="info-titel">{{ $bestaetigungTitel !== '' ? $bestaetigungTitel : 'Ohne Titel' }}</h2>
            @if (trim($infoMarkdown) === '')
                <p class="text-sm text-[var(--ui-muted)]">Noch kein Text.</p>
            @else
                <div class="info-text">{!! $this->vorschauHtml !!}</div>
            @endif
        </div>
        <x-slot name="footer">
            <div class="flex justify-end">
                <x-ui-button type="button" variant="secondary-outline" wire:click="$set('vorschauOffen', false)">Schließen</x-ui-button>
            </div>
        </x-slot>
    </x-ui-modal>
</x-ui-page>
