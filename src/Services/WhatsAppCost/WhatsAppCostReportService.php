<?php

namespace Platform\Recruiting\Services\WhatsAppCost;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class WhatsAppCostReportService
{
    public function build(
        int $teamId,
        CarbonInterface $from,
        CarbonInterface $to,
        string $typeFilter = 'all',
    ): WhatsAppCostReport {
        $query = DB::table('comms_whatsapp_messages as m')
            ->join('comms_whatsapp_threads as t', 'm.comms_whatsapp_thread_id', '=', 't.id')
            ->where('t.team_id', $teamId)
            // DB::table() bypasses Eloquent SoftDeletes — filter soft-deleted threads manually.
            ->whereNull('t.deleted_at')
            ->where('m.direction', 'outbound')
            // Nur echte Template-Versände verursachen Kosten. Freitext-/Session-
            // Antworten (template_name = NULL) im offenen 24h-Fenster sind kostenlos.
            ->whereNotNull('m.template_name')
            ->whereIn('m.status', ['delivered', 'read'])
            ->whereBetween('m.delivered_at', [$from, $to]);

        if ($typeFilter === 'manual') {
            $query->whereNotNull('m.sent_by_user_id');
        } elseif ($typeFilter === 'automatic') {
            $query->whereNull('m.sent_by_user_id');
        }

        $rows = $query
            ->selectRaw('m.template_name as template_name')
            ->selectRaw('(m.sent_by_user_id is not null) as is_manual')
            ->selectRaw('count(*) as count')
            ->groupByRaw('m.template_name, (m.sent_by_user_id is not null)')
            ->get()
            ->map(fn ($r) => [
                'template_name' => $r->template_name,
                'is_manual' => (bool) $r->is_manual,
                'count' => (int) $r->count,
            ])
            ->all();

        $basePrice = (float) config('recruiting.whatsapp_costs.price_per_delivered_template', 0.055);
        $feePercent = (float) config('recruiting.whatsapp_costs.fee_percent', 0);
        $currency = (string) config('recruiting.whatsapp_costs.currency', 'EUR');

        // Service-Aufschlag in den effektiven Preis einrechnen — der Kunde sieht den
        // Endpreis inkl. Aufschlag, ohne dass er separat ausgewiesen wird.
        $price = $basePrice * (1 + $feePercent / 100);

        return WhatsAppCostReport::fromRows($rows, $price, $currency);
    }
}
