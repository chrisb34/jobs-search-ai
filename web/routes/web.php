<?php

use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\InterestingJobController;
use App\Http\Controllers\JobfinderConfigController;
use App\Http\Controllers\SetupWizardController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('interesting-jobs.index'));
Route::get('/console', [ConsoleController::class, 'index'])->name('console.index');
Route::post('/console/run', [ConsoleController::class, 'run'])->name('console.run');
Route::get('/config-editor', [JobfinderConfigController::class, 'index'])->name('jobfinder-config.index');
Route::post('/config-editor', [JobfinderConfigController::class, 'update'])->name('jobfinder-config.update');
Route::get('/setup-wizard', [SetupWizardController::class, 'index'])->name('setup-wizard.index');
Route::post('/setup-wizard/database', [SetupWizardController::class, 'saveDatabase'])->name('setup-wizard.database');
Route::post('/setup-wizard/generate', [SetupWizardController::class, 'generate'])->name('setup-wizard.generate');
Route::get('/interesting-jobs', [InterestingJobController::class, 'index'])->name('interesting-jobs.index');
Route::get('/interesting-jobs/{interestingJob}/edit', [InterestingJobController::class, 'edit'])->name('interesting-jobs.edit');
Route::post('/interesting-jobs/{interestingJob}', [InterestingJobController::class, 'update'])->name('interesting-jobs.update');
Route::post('/interesting-jobs/{interestingJob}/quick-action', [InterestingJobController::class, 'quickAction'])
    ->name('interesting-jobs.quick-action');
Route::post('/interesting-jobs/{interestingJob}/generate-cover-letter', [InterestingJobController::class, 'generateCoverLetter'])
    ->name('interesting-jobs.generate-cover-letter');
