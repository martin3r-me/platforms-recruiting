<?php

namespace Platform\Recruiting\Livewire\Dispo;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecZasDispoInboundFile;
use Platform\Recruiting\Services\Zas\DispoColumnProfiler;
use Platform\Recruiting\Services\Zas\DispoInboundInspector;
use Platform\Recruiting\Support\CsvEncodingNormalizer;

/**
 * Disposition → ZAS-Eingang → Detail: eine Dispo-Datei gesichtet.
 *
 * Detailtabelle capt bei ROW_CAP Zeilen (stuendlicher Voll-Bestand mit
 * VAxPerson-Zeilen wird fuenfstellig); die Spaltenuebersicht rechnet
 * bewusst ueber die GANZE Datei.
 */
class Show extends Component
{
    public const ROW_CAP = 200;

    public int $fileId;

    public function mount(int $fileId): void
    {
        $this->fileId = $fileId;
    }

    #[Computed]
    public function file(): RecZasDispoInboundFile
    {
        return RecZasDispoInboundFile::findOrFail($this->fileId);
    }

    /**
     * Geparste Struktur — bewusst KEINE public property: das komplette
     * Zeilen-Array laege sonst bei jedem Request im serialisierten
     * Component-State. #[Computed] ist request-lokal.
     *
     * Fehlerbehandlung: Datei auf Storage kann gelöscht/bewegt sein, oder
     * Disk-Config hat sich geändert. In beiden Fällen wird graceful
     * ['format' => 'missing'] zurückgegeben (nicht Silent-Coerce zu '').
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function parsed(): array
    {
        $file = $this->file;

        // Prüfe erst ob Datei auf Storage existiert
        if (! Storage::disk($file->disk)->exists($file->stored_path)) {
            return ['format' => 'missing'];
        }

        // Belt & Braces: Auch get() kann fehlschlagen (Disk-Config geändert, etc.)
        try {
            $raw = (string) Storage::disk($file->disk)->get($file->stored_path);
        } catch (\Throwable) {
            return ['format' => 'missing'];
        }

        $utf8 = CsvEncodingNormalizer::toUtf8($raw);

        $inspector = new DispoInboundInspector();
        $format    = $inspector->detectFormat($utf8);

        if ($format === 'csv') {
            $csv = $inspector->inspectCsv($utf8);
            $columns = array_merge($csv['columns'], $csv['extra_columns']);

            return [
                'format'    => 'csv',
                'columns'   => $columns,
                'row_count' => $csv['row_count'],
                'rows'      => array_slice($csv['rows'], 0, self::ROW_CAP),
                'profile'   => (new DispoColumnProfiler())->profile($columns, $csv['rows']),
            ];
        }

        if ($format === 'json') {
            $pretty = json_encode(
                json_decode($utf8),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            return ['format' => 'json', 'pretty' => (string) $pretty];
        }

        return ['format' => 'unknown', 'raw_excerpt' => mb_substr($utf8, 0, 20000)];
    }

    public function render()
    {
        return view('recruiting::livewire.dispo.show')
            ->layout('platform::layouts.app');
    }
}
