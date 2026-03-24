<?php

use App\Http\Controllers\InterestingJobController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('interesting-jobs.index'));
Route::get('/interesting-jobs', [InterestingJobController::class, 'index'])->name('interesting-jobs.index');
Route::get('/interesting-jobs/{interestingJob}/edit', [InterestingJobController::class, 'edit'])->name('interesting-jobs.edit');
Route::post('/interesting-jobs/{interestingJob}', [InterestingJobController::class, 'update'])->name('interesting-jobs.update');
