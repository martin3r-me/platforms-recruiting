<?php

namespace Platform\Recruiting\Livewire\Contracts;

use Livewire\Component;
use Livewire\Attributes\Computed;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecContractTemplate;

class Index extends Component
{
    public $search = '';
    public $filterStatus = 'all';
    public $filterTemplateId = 'all';

    public function render()
    {
        return view('recruiting::livewire.contracts.index')
            ->layout('platform::layouts.app');
    }

    #[Computed]
    public function contracts()
    {
        $teamId = auth()->user()->currentTeam->id;

        return RecContract::query()
            ->with(['contractTemplate', 'applicant.crmContactLinks.contact'])
            ->where('team_id', $teamId)
            ->when($this->search, function ($q) {
                $q->where(function ($q) {
                    $q->where('notes', 'like', '%' . $this->search . '%')
                        ->orWhereHas('applicant.crmContactLinks.contact', function ($query) {
                            $query->where('first_name', 'like', '%' . $this->search . '%')
                                ->orWhere('last_name', 'like', '%' . $this->search . '%');
                        })
                        ->orWhereHas('contractTemplate', function ($query) {
                            $query->where('name', 'like', '%' . $this->search . '%');
                        });
                });
            })
            ->when($this->filterStatus !== 'all', fn($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterTemplateId !== 'all', fn($q) => $q->where('rec_contract_template_id', $this->filterTemplateId))
            ->orderBy('created_at', 'desc')
            ->get();
    }

    #[Computed]
    public function templates()
    {
        $teamId = auth()->user()->currentTeam->id;

        return RecContractTemplate::where('team_id', $teamId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }
}
