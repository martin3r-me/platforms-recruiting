{{--
    Karte „Portal als App nutzen“ (Spec Jobs anbieten 6.6/6.7, Markus 13.11.2026).
    Nur im angemeldeten Start-Bereich eingebunden (portal-shell). Ersetzt den
    Tipp „auf den Startbildschirm legen“ vom 29.10.2026.

    Alles Entscheidende laeuft im Browser und ist dort optional: fehlt eine
    Schnittstelle (aeltere iPhones, In-App-Browser), bleibt die Karte einfach
    weg oder zeigt weniger — jeder Zugriff steht in try/catch, x-cloak haelt
    sie ohne Alpine unsichtbar.
      · Laeuft schon als App ⇒ kein Installationsteil.
      · Android/Chrome (beforeinstallprompt) ⇒ Knopf, der die Nachfrage ausloest.
      · iPhone/iPad in Safari ⇒ Anleitung in zwei Schritten; Apple erlaubt keinen Knopf.
      · Sonst auf Touch-Geraeten (Firefox/Samsung auf Android, Chrome/In-App auf
        dem iPhone) ⇒ die Menue-Zeile des alten Tipps (Review Task 8, ersetzt den
        Brief-Satz „sonst kein Installationsteil“).
      · „an“ zeigt die Karte nur, wenn der SERVER das Abo dieser Person kennt
        (pushAboStatus / Rueckgabe von pushAboSpeichern) — nie aus dem Browser allein.
      · Push nur mit Push-Dienst ($pushAktiv, sonst nicht im Markup). Erlaubnis
        erst nach dem Tipp auf den Knopf, und zwar als ERSTES im Klick — Safari
        verlangt die Nachfrage direkt aus der Beruehrung heraus.

    Das Komponenten-Objekt steht bewusst im x-data selbst und nicht in einem
    Alpine.data aus einem Skript: nach der Anmeldung kommt die Karte per
    Livewire-Morph auf die Seite, dabei laufen <script>-Bloecke nicht. Deshalb
    faengt das Layout (layouts/portal) beforeinstallprompt schon beim ersten
    Laden ab (Gesamtpruefung Minor 2); die Karte liest window.rgInstallNachfrage.
--}}
@php
    $appInstallText = $duzen
        ? 'Leg dir das Portal auf den Startbildschirm — dann ist es mit einem Tipp da, wie eine App.'
        : 'Legen Sie sich das Portal auf den Startbildschirm — dann ist es mit einem Tipp da, wie eine App.';
    $appSchritt1 = $duzen ? 'Tippe auf das „Teilen“-Symbol' : 'Tippen Sie auf das „Teilen“-Symbol';
    $appSchritt2 = $duzen ? 'Wähle „Zum Home-Bildschirm“.' : 'Wählen Sie „Zum Home-Bildschirm“.';
    $pushText = $duzen
        ? 'Wir sagen dir Bescheid, sobald es neue Einsätze für dich gibt.'
        : 'Wir sagen Ihnen Bescheid, sobald es neue Einsätze für Sie gibt.';
    $pushAbgelehntText = $duzen
        ? 'Benachrichtigungen sind auf diesem Gerät blockiert. Du kannst sie in den Einstellungen des Browsers wieder erlauben.'
        : 'Benachrichtigungen sind auf diesem Gerät blockiert. Sie können sie in den Einstellungen des Browsers wieder erlauben.';
    // Der oeffentliche Schluessel kommt nur ins Markup, wenn es einen Push-Dienst gibt.
    $appSchluessel = $pushAktiv ? $vapidPublic : '';
    // Wortlaut des alten Tipps (29.10.2026) als Rueckfall-Zeile.
    $appMenueTipp = ($duzen ? 'Tipp: Leg dir das Portal auf den Startbildschirm.' : 'Tipp: Legen Sie sich das Portal auf den Startbildschirm.')
        . ' iPhone: Teilen › „Zum Home-Bildschirm“. Android: Menü ⋮ › „Zum Startbildschirm hinzufügen“.';
@endphp

<div class="card kachel" style="margin-top:11px" x-cloak x-show="sichtbar"
     x-data="{
        weg: false,
        alsApp: false,
        ios: false,
        iosSafari: false,
        beruehrung: false,
        nachfrage: null,
        pushServer: @js($pushAktiv),
        schluessel: @js($appSchluessel),
        pushDa: false,
        push: 'aus',
        init() {
            try { this.weg = localStorage.getItem('rg-portal-app-karte') === 'weg'; } catch (e) {}
            try { this.alsApp = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true; } catch (e) {}
            try {
                var ua = navigator.userAgent || '';
                this.ios = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
                this.iosSafari = this.ios && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS|GSA|FBAN|FBAV|Instagram|Line/.test(ua);
            } catch (e) {}
            try { this.beruehrung = window.matchMedia('(pointer: coarse)').matches; } catch (e) {}
            try {
                this.nachfrage = window.rgInstallNachfrage || null;
                window.addEventListener('rg-install-bereit', () => { this.nachfrage = window.rgInstallNachfrage || null; });
                window.addEventListener('beforeinstallprompt', (e) => { if (this.weg) { return; } e.preventDefault(); window.rgInstallNachfrage = e; this.nachfrage = e; });
                window.addEventListener('appinstalled', () => { this.nachfrage = null; window.rgInstallNachfrage = null; });
            } catch (e) {}
            try { this.pushDa = this.pushServer && this.schluessel !== '' && ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window); } catch (e) { this.pushDa = false; }
            if (!this.pushDa) { return; }
            try {
                if (Notification.permission === 'denied') {
                    this.push = 'abgelehnt';
                } else if (Notification.permission === 'granted') {
                    navigator.serviceWorker.getRegistration('/recruiting/')
                        .then((r) => r ? r.pushManager.getSubscription() : null)
                        .then((a) => a ? this.$wire.pushAboStatus(a.endpoint) : false)
                        .then((meins) => { if (meins === true) { this.push = 'an'; } })
                        .catch(() => {});
                }
            } catch (e) {}
        },
        get installTeil() { return !this.alsApp && (this.nachfrage !== null || this.iosSafari || this.beruehrung); },
        get menueTipp() { return this.nachfrage === null && !this.iosSafari; },
        get iosHinweis() { return this.pushServer && this.iosSafari && !this.alsApp; },
        get pushTeil() { return this.pushServer && (this.pushDa || this.iosHinweis); },
        get sichtbar() { return !this.weg && (this.installTeil || this.pushTeil); },
        wegklicken() {
            this.weg = true;
            try { localStorage.setItem('rg-portal-app-karte', 'weg'); } catch (e) {}
        },
        async installieren() {
            var n = this.nachfrage;
            if (!n) { return; }
            try { n.prompt(); await n.userChoice; } catch (e) {}
            this.nachfrage = null;
            window.rgInstallNachfrage = null;
        },
        async einschalten() {
            if (this.push === 'laeuft') { return; }
            this.push = 'laeuft';
            try {
                var erlaubnis = await Notification.requestPermission();
                if (erlaubnis !== 'granted') { this.push = erlaubnis === 'denied' ? 'abgelehnt' : 'aus'; return; }
                await navigator.serviceWorker.register('/recruiting/portal-app/sw.js', { scope: '/recruiting/' });
                var reg = await navigator.serviceWorker.ready;
                var abo = await reg.pushManager.getSubscription();
                if (!abo) { abo = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: this.schluesselBytes() }); }
                var gespeichert = await this.$wire.pushAboSpeichern(abo.toJSON());
                this.push = gespeichert === true ? 'an' : 'fehler';
            } catch (e) {
                this.push = 'fehler';
            }
        },
        async ausschalten() {
            try {
                var reg = await navigator.serviceWorker.getRegistration('/recruiting/');
                var abo = reg ? await reg.pushManager.getSubscription() : null;
                if (abo) {
                    var geloescht = await this.$wire.pushAboLoeschen(abo.endpoint);
                    if (geloescht !== true) { this.push = 'fehler'; return; }
                    await abo.unsubscribe();
                }
                this.push = 'aus';
            } catch (e) {
                this.push = 'fehler';
            }
        },
        schluesselBytes() {
            var b64 = this.schluessel.replace(/-/g, '+').replace(/_/g, '/');
            b64 += '='.repeat((4 - b64.length % 4) % 4);
            var roh = atob(b64);
            var bytes = new Uint8Array(roh.length);
            for (var i = 0; i < roh.length; i++) { bytes[i] = roh.charCodeAt(i); }
            return bytes;
        }
     }">
    <div class="upload-head">
        <h3>Portal als App nutzen</h3>
        <button type="button" class="upload-close" aria-label="Karte ausblenden" @click="wegklicken()">×</button>
    </div>

    <div x-show="installTeil" style="display:flex;flex-direction:column;gap:11px">
        <p x-show="!menueTipp">{{ $appInstallText }}</p>
        <p class="klein" x-show="menueTipp">{{ $appMenueTipp }}</p>
        <button type="button" class="btn primary" x-show="nachfrage !== null" @click="installieren()">Auf den Startbildschirm</button>
        <ol x-show="nachfrage === null && iosSafari" style="margin:0;padding-left:20px;color:var(--ink-2);font-size:14px;line-height:1.7">
            <li>
                {{ $appSchritt1 }}
                <svg width="16" height="18" viewBox="0 0 16 18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-3px">
                    <path d="M8 11V1.5M4.5 5 8 1.5 11.5 5"/>
                    <path d="M5.5 7.5H3a1 1 0 0 0-1 1V16a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V8.5a1 1 0 0 0-1-1h-2.5"/>
                </svg>
            </li>
            <li>{{ $appSchritt2 }}</li>
        </ol>
    </div>

    @if ($pushAktiv)
        <div x-show="pushTeil" style="display:flex;flex-direction:column;gap:11px">
            <p class="klein" x-show="iosHinweis">Erst auf den Startbildschirm legen, dann lassen sich Benachrichtigungen einschalten.</p>
            <div x-show="!iosHinweis && pushDa" style="display:flex;flex-direction:column;gap:11px">
                <p x-show="push === 'aus' || push === 'laeuft' || push === 'fehler'">{{ $pushText }}</p>
                <button type="button" class="btn primary" x-show="push === 'aus' || push === 'laeuft' || push === 'fehler'"
                        :disabled="push === 'laeuft'" @click="einschalten()">Benachrichtigungen einschalten</button>
                <p class="klein" x-show="push === 'fehler'">Das hat nicht geklappt. Bitte später noch einmal versuchen.</p>
                <p class="klein" x-show="push === 'abgelehnt'">{{ $pushAbgelehntText }}</p>
                <div x-show="push === 'an'" style="display:flex;align-items:center;justify-content:space-between;gap:11px">
                    <p>Benachrichtigungen sind an</p>
                    <button type="button" class="btn" @click="ausschalten()">Ausschalten</button>
                </div>
            </div>
        </div>
    @endif
</div>
