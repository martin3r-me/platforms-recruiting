<?php

use Illuminate\Support\Facades\Route;
use Platform\Recruiting\Livewire\Dashboard\Dashboard;
use Platform\Recruiting\Livewire\Position\Index as PositionIndex;
use Platform\Recruiting\Livewire\Position\Show as PositionShow;
use Platform\Recruiting\Livewire\Posting\Index as PostingIndex;
use Platform\Recruiting\Livewire\Posting\Show as PostingShow;
use Platform\Recruiting\Livewire\Applicant\Index as ApplicantIndex;
use Platform\Recruiting\Livewire\Applicant\Show as ApplicantShow;
use Platform\Recruiting\Livewire\ApplicantStatus\Index as ApplicantStatusIndex;

// Dashboard
Route::get('/', Dashboard::class)->name('recruiting.dashboard');

// Stellen
Route::get('/positions', PositionIndex::class)->name('recruiting.positions.index');
Route::get('/positions/{position}', PositionShow::class)->name('recruiting.positions.show');

// Ausschreibungen
Route::get('/postings', PostingIndex::class)->name('recruiting.postings.index');
Route::get('/postings/{posting}', PostingShow::class)->name('recruiting.postings.show');

// Bewerber
Route::get('/applicants', ApplicantIndex::class)->name('recruiting.applicants.index');
Route::get('/applicants/{applicant}', ApplicantShow::class)->name('recruiting.applicants.show');

// Lookups
Route::get('/applicant-statuses', ApplicantStatusIndex::class)->name('recruiting.applicant-statuses.index');
