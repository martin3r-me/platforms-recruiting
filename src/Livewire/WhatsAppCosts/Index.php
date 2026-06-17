<?php

namespace Platform\Recruiting\Livewire\WhatsAppCosts;

use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Services\WhatsAppCost\WhatsAppCostReport;
use Platform\Recruiting\Services\WhatsAppCost\WhatsAppCostReportService;

class Index extends Component
{
    public string $from = '';
    public string $to = '';
    public string $type = 'all'; // all | manual | automatic

    public function mount(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->endOfMonth()->toDateString();
    }

    #[Computed]
    public function report(): WhatsAppCostReport
    {
        $teamId = auth()->user()->currentTeam->id;

        return app(WhatsAppCostReportService::class)->build(
            $teamId,
            Carbon::parse($this->from)->startOfDay(),
            Carbon::parse($this->to)->endOfDay(),
            $this->type,
        );
    }

    public function render()
    {
        return view('recruiting::livewire.whatsapp-costs.index')
            ->layout('platform::layouts.app');
    }
}
