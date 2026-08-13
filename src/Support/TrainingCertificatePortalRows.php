<?php

namespace Platform\Recruiting\Support;

/**
 * Die Zertifikat-Zeile der beiden Portal-Listen — und das Anhaengen an die
 * Vertragszeilen.
 *
 * Laravel-frei, und das ist der ganze Zweck der Klasse: die Zeilen entstehen in
 * EmployeePortal und ApplicantPortal, und Livewire-Komponenten sind in diesem
 * Modul nicht instanziierbar (kein Laravel-Bootstrap, kein testbench). Was hier
 * liegt, ist gemessen; was dort bleibt (Query, route(), Zuweisung), ist nur per
 * Sichtpruefung abzudecken. Deshalb liegt hier genau der Teil, an dem die zwei
 * Pflichten des Tasks haengen: die Form der Zeile und der Zustand, der aus der
 * Zeilenmenge folgt.
 *
 * DIE ZEILE HAT DIE FORM DER VERTRAGSZEILE, nicht eine eigene: beide Blades
 * laufen mit einem @foreach ueber EINE Liste und lesen $c['status'],
 * $c['signed_at'], $c['sign_url'] und $c['pdf_url'] unbedingt. Ein fehlender
 * Schluessel waere eine "Undefined array key"-Warnung mitten in der Liste des
 * Bewerbers. Festgenagelt in TrainingCertificatePortalRowsTest.
 */
final class TrainingCertificatePortalRows
{
    /**
     * Wie das Dokument in der Liste heisst.
     *
     * KEIN Vorlagenname: seit dem Zuschnitt v3 gibt es keine Zertifikat-Vorlage
     * mehr, der Inhalt steht in TrainingCertificateContent. Der Text ist
     * derselbe wie am Checkbox-Label des HR-Schreibtischs — ein Dokument, ein
     * Name (abgesichert in TrainingCertificatePortalRowsTest).
     *
     * SO ist eine ZWEITE Schulungsart hier einzubauen, falls sie kommt: dieser
     * Konstante eine Abbildung `kind => Anzeigename` daneben stellen und
     * row() den `kind` des Zertifikats mitgeben — row() bekommt die Zeilen
     * bewusst ungefiltert nach `kind` (ein Bewerber darf Zertifikate mehrerer
     * Arten haben), ein zweiter Name waere hier also sonst still falsch.
     */
    public const DISPLAY_NAME = 'Teilnahme-Zertifikat';

    /**
     * Der Status der Zeile.
     *
     * Eigener Wert und NICHT 'completed': 'completed' bedeutet in der
     * Vertragswelt „unterschrieben und fertig gerendert". Am Wert haengen die
     * beiden Blades — der issued-Zweig steht dort VOR der
     * `completed || signed_at`-Bedingung, sonst behauptet die Zeile
     * „Unterschrieben am ..." ueber ein Dokument, das niemand unterschrieben
     * hat (PortalCertificateBadgeTest misst das an der gerenderten Blade).
     */
    public const STATUS = 'issued';

    public const STATE_EMPTY = 'empty';

    public const STATE_READY = 'ready';

    /**
     * Eine Zertifikat-Zeile in der Form der Vertragszeilen.
     *
     * signed_at UND completed_at tragen das Ausstellungsdatum, statt einen
     * dritten Datums-Schluessel einzufuehren: die Zeile soll denselben gruenen
     * Erledigt-Zustand haben wie ein fertiger Vertrag, nur mit anderem Wort.
     *
     * sign_url ist null, weil es nichts zu unterschreiben gibt. Der
     * Unterschreiben-Button der Blades verlangt 'sent'/'in_progress' und bleibt
     * damit von allein weg; der PDF-Button haengt allein an pdf_url und
     * erscheint von allein. Beides an der Blade gemessen, nicht angenommen
     * (PortalCertificateBadgeTest).
     *
     * @param int|string $certificateId ID des Zertifikats (rec_training_certificates.id)
     * @param mixed      $issuedAt      Ausstellungszeitpunkt, wie das Model ihn liefert (Carbon)
     * @param string     $pdfUrl        die oeffentliche PDF-Route ueber die uuid
     *
     * @return array<string,mixed>
     */
    public static function row(int|string $certificateId, mixed $issuedAt, string $pdfUrl): array
    {
        return [
            // Praefix, weil Vertrags-IDs und Zertifikat-IDs beide bei 1
            // anfangen und in EINER Liste landen.
            'id'           => 'cert-' . $certificateId,
            'display_name' => self::DISPLAY_NAME,
            'status'       => self::STATUS,
            'signed_at'    => $issuedAt,
            'completed_at' => $issuedAt,
            'sign_url'     => null,
            'pdf_url'      => $pdfUrl,
        ];
    }

    /**
     * Zertifikat-Zeilen hinter die Vertragszeilen haengen.
     *
     * array_values auf beiden Seiten: die Vertragszeilen kommen aus einem
     * ->filter() auf einer Eloquent-Collection, also potenziell mit Loechern in
     * den Schluesseln.
     *
     * @param array<int|string,array<string,mixed>> $contractRows
     * @param array<int|string,array<string,mixed>> $certificateRows
     *
     * @return list<array<string,mixed>>
     */
    public static function append(array $contractRows, array $certificateRows): array
    {
        return array_merge(array_values($contractRows), array_values($certificateRows));
    }

    /**
     * Dasselbe, plus der Anzeigezustand des Bewerber-Portals.
     *
     * Zusammen in EINEM Aufruf, und das ist der Kern der Auflage: die
     * `state`-Zeile des Bewerber-Portals zaehlte die Vertragszeilen. Wer die
     * Zertifikate erst DANACH anhaengt, laesst das Portal sich fuer leer
     * erklaeren, waehrend ein Zertifikat darin liegt — und genau das ist der
     * Regelfall, denn ein abgelehnter Nicht-EU-Bewerber hat typischerweise
     * keine Vertraege. Die Reihenfolge ist hier nicht dokumentiert, sondern
     * weggenommen: beide Werte kommen aus diesem Aufruf, der Aufrufer
     * destrukturiert sie in einer Anweisung.
     *
     * @param array<int|string,array<string,mixed>> $contractRows
     * @param array<int|string,array<string,mixed>> $certificateRows
     *
     * @return array{0: list<array<string,mixed>>, 1: string}
     */
    public static function appendWithState(array $contractRows, array $certificateRows): array
    {
        $rows = self::append($contractRows, $certificateRows);

        return [$rows, $rows === [] ? self::STATE_EMPTY : self::STATE_READY];
    }
}
