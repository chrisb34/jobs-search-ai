<?php

namespace App\Http\Controllers;

use App\Services\CvTextExtractor;
use App\Services\DatabaseSettingsManager;
use App\Services\SetupWizardFiles;
use App\Services\SetupWizardGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;
use RuntimeException;

class SetupWizardController extends Controller
{
    public function index(
        Request $request,
        DatabaseSettingsManager $databaseSettings,
        SetupWizardFiles $files
    ): View {
        return view('setup-wizard.index', [
            'dbSettings' => $databaseSettings->current(),
            'generatedFiles' => $files->currentLocalFiles(),
            'wizardResult' => $request->session()->get('wizard_result'),
        ]);
    }

    public function saveDatabase(
        Request $request,
        DatabaseSettingsManager $databaseSettings
    ): RedirectResponse {
        $validated = $request->validate([
            'connection' => ['required', 'in:sqlite,mysql,mariadb,pgsql'],
            'database' => ['required', 'string', 'max:255'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'string', 'max:20'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $databaseSettings->validateAndSave($validated);
            Artisan::call('config:clear');
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('setup-wizard.index')
                ->withInput()
                ->with('status', $exception->getMessage());
        }

        return redirect()
            ->route('setup-wizard.index')
            ->with('status', 'Database settings saved and validated.');
    }

    public function generate(
        Request $request,
        CvTextExtractor $extractor,
        SetupWizardGenerator $generator,
        SetupWizardFiles $files
    ): RedirectResponse {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ignore_user_abort(true);

        $validated = $request->validate([
            'cv_file' => ['required', 'file', 'mimes:txt,md,docx,doc,pdf', 'max:5120'],
            'extra_context' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $cvText = $extractor->extract($validated['cv_file']);
            if (trim($cvText) === '') {
                throw new RuntimeException('The uploaded CV did not contain readable text.');
            }

            $generated = $generator->generateFromCv(
                $cvText,
                (string) ($validated['extra_context'] ?? '')
            );

            $files->saveCriteria($generated['criteria']);
            $files->saveApplicant($generated['applicant']);
            Artisan::call('config:clear');
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('setup-wizard.index')
                ->with('status', $exception->getMessage());
        }

        return redirect()
            ->route('setup-wizard.index')
            ->with('status', 'Local criteria and applicant profile generated from CV.')
            ->with('wizard_result', [
                'cv_length' => mb_strlen($cvText),
                'criteria_preview' => $generated['criteria'],
                'applicant_preview' => $generated['applicant'],
            ]);
    }
}
