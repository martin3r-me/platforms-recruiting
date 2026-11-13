/*
 * Service Worker des Mitarbeiterportals (Spec Jobs anbieten 6.6/6.7, Markus 13.11.2026).
 *
 * Bewusst NUR zwei Ereignisse: Push anzeigen und das Antippen beantworten.
 * Kein Abfangen von Anfragen, kein Cache — das Portal laedt immer frisch vom Server.
 * Ausgeliefert ueber PortalAppController::sw (Service-Worker-Allowed: /recruiting/).
 */

var PORTAL = '/recruiting/';

// Nur Ziele im Portal dieser Seite; alles andere faellt auf den Portal-Anfang.
function ziel(url) {
    if (typeof url === 'string' && url.indexOf(PORTAL) === 0) {
        return new URL(url, self.location.origin).href;
    }
    return new URL(PORTAL, self.location.origin).href;
}

self.addEventListener('push', function (event) {
    var daten = {};
    try {
        daten = event.data ? event.data.json() : {};
    } catch (e) {
        daten = {};
    }

    event.waitUntil(self.registration.showNotification(daten.title || 'Mein Rheingedeck', {
        body: daten.body || '',
        icon: '/recruiting/portal-app/icon-192.png',
        data: { url: ziel(daten.url) }
    }));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = ziel(event.notification.data && event.notification.data.url);

    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (fenster) {
        var vorhanden = fenster.find(function (f) {
            return f.url.indexOf(self.location.origin + PORTAL) === 0 && 'focus' in f;
        });
        if (!vorhanden) {
            return self.clients.openWindow(url);
        }
        // navigate() geht nur bei Fenstern unter Kontrolle dieses Workers —
        // sonst lieber ein neues Fenster als gar keins.
        return vorhanden.focus().then(function (f) {
            var c = f || vorhanden;
            return c.navigate ? c.navigate(url) : self.clients.openWindow(url);
        }).catch(function () {
            return self.clients.openWindow(url);
        });
    }));
});
