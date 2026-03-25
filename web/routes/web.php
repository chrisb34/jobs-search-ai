<?php

use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\InterestingJobController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('interesting-jobs.index'));
Route::get('/console', [ConsoleController::class, 'index'])->name('console.index');
Route::post('/console/run', [ConsoleController::class, 'run'])->name('console.run');
Route::get('/interesting-jobs', [InterestingJobController::class, 'index'])->name('interesting-jobs.index');
Route::get('/interesting-jobs/{interestingJob}/edit', [InterestingJobController::class, 'edit'])->name('interesting-jobs.edit');
Route::post('/interesting-jobs/{interestingJob}', [InterestingJobController::class, 'update'])->name('interesting-jobs.update');
Route::post('/interesting-jobs/{interestingJob}/generate-cover-letter', [InterestingJobController::class, 'generateCoverLetter'])
    ->name('interesting-jobs.generate-cover-letter');
