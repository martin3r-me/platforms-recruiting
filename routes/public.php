<?php

use Illuminate\Support\Facades\Route;
use Platform\Recruiting\Livewire\Public\ApplicantForm;

Route::get('/a/{publicToken}', ApplicantForm::class)
    ->name('recruiting.public.applicant-form');
