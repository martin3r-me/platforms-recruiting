<?php

use Illuminate\Support\Facades\Route;
use Platform\Recruiting\Livewire\Dashboard\Dashboard;
use Platform\Recruiting\Livewire\Position\Index as PositionIndex;
use Platform\Recruiting\Livewire\Position\Show as PositionShow;
use Platform\Recruiting\Livewire\Posting\Index as PostingIndex;
use Platform\Recruiting\Livewire\Posting\Show as PostingShow;
use Platform\Recruiting\Livewire\Applicant\Index as ApplicantIndex;
use Platform\Recruiting\Livewire\Applicant\Show as ApplicantShow;
use Platform\Recruiting\Livewire\InterviewTypes\Index as InterviewTypeIndex;
use Platform\Recruiting\Livewire\InterviewSchedule\Index as InterviewScheduleIndex;
use Platform\Recruiting\Livewire\InterviewBookings\Index as InterviewBookingsIndex;
use Platform\Recruiting\Livewire\Contracts\Index as ContractsIndex;
use Platform\Recruiting\Livewire\ContractTemplates\Index as ContractTemplatesIndex;

// Dashboard
Route::get('/', Dashboard::class)->name('recruiting.dashboard');
Route::get('/parked', Dashboard::class)->name('recruiting.dashboard.parked');

// Dashboard-alt (Legacy): zeigt Stellen mit '_old'-Suffix + alte
// 2-Phasen-Logik. Temporaere Bruecke bis Migration alter Bewerber durch.
Route::get('/dashboard-alt', \Platform\Recruiting\Livewire\Dashboard\DashboardLegacy::class)
    ->name('recruiting.dashboard.legacy');

// Test-Dashboard fuer Sandbox-Positionen (Direct-URL, nicht im Sidebar)
Route::get('/testdashboard', \Platform\Recruiting\Livewire\Dashboard\TestDashboard::class)
    ->name('recruiting.dashboard.test');

// HR-Schreibtisch eigene Komponente (nicht mehr Dashboard-conditional)
Route::get('/hr-desk', \Platform\Recruiting\Livewire\HrDesk\Index::class)
    ->name('recruiting.dashboard.hr-desk');

// Stellen
Route::get('/positions', PositionIndex::class)->name('recruiting.positions.index');
Route::get('/positions/{position}', PositionShow::class)->name('recruiting.positions.show');

// Ausschreibungen
Route::get('/postings', PostingIndex::class)->name('recruiting.postings.index');
Route::get('/postings/{posting}', PostingShow::class)->name('recruiting.postings.show');

// Bewerber
Route::get('/applicants', ApplicantIndex::class)->name('recruiting.applicants.index');
Route::get('/applicants/{applicant}', ApplicantShow::class)->name('recruiting.applicants.show');

// Mitarbeiter (HR-Backend, getrennt vom Bewerber-Funnel)
Route::get('/employees', \Platform\Recruiting\Livewire\Employees\Index::class)
    ->name('recruiting.employees.index');
Route::get('/employees/payroll-changes', \Platform\Recruiting\Livewire\Employees\PayrollChanges::class)
    ->name('recruiting.employees.payroll-changes');
Route::get('/employees/payroll-changes.csv', \Platform\Recruiting\Http\Controllers\PayrollChangesExportController::class)
    ->name('recruiting.employees.payroll-changes.csv');
Route::get('/employees/{employee}', \Platform\Recruiting\Livewire\Employees\Show::class)
    ->name('recruiting.employees.show');

// Eingangs-Inbox: Bewerbungen ohne erkannte Quelle (is_unrouted = true)
Route::get('/inbox', \Platform\Recruiting\Livewire\Inbox\Index::class)->name('recruiting.inbox.index');

// Interview-Termine
Route::get('/interview-types', InterviewTypeIndex::class)->name('recruiting.interview-types.index');
Route::get('/interview-schedule', InterviewScheduleIndex::class)->name('recruiting.interview-schedule.index');
Route::get('/interview-bookings/{interview}', InterviewBookingsIndex::class)->name('recruiting.interview-bookings.index');
Route::get('/interview-waitlist', \Platform\Recruiting\Livewire\Waitlist\Index::class)->name('recruiting.interview-waitlist.index');

// Verträge
Route::get('/contracts', ContractsIndex::class)->name('recruiting.contracts.index');
Route::get('/contract-templates', ContractTemplatesIndex::class)->name('recruiting.contract-templates.index');
