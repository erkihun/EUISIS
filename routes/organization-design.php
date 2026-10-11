<?php

declare(strict_types=1);

use App\Http\Controllers\OrganizationDesign\ClassificationController;
use App\Http\Controllers\OrganizationDesign\CompetencyCatalogController;
use App\Http\Controllers\OrganizationDesign\DesignStudyController;
use App\Http\Controllers\OrganizationDesign\FunctionController;
use App\Http\Controllers\OrganizationDesign\JobDescriptionController;
use App\Http\Controllers\OrganizationDesign\MandateController;
use App\Http\Controllers\OrganizationDesign\ManpowerPlanController;
use App\Http\Controllers\OrganizationDesign\MethodologyController;
use App\Http\Controllers\OrganizationDesign\PositionDesignController;
use App\Http\Controllers\OrganizationDesign\ProcessController;
use App\Http\Controllers\OrganizationDesign\ReportController;
use App\Http\Controllers\OrganizationDesign\ProcessDesignController;
use App\Http\Controllers\OrganizationDesign\ProfessionController;
use App\Http\Controllers\OrganizationDesign\RequirementController;
use App\Http\Controllers\OrganizationDesign\ResponsibilityController;
use App\Http\Controllers\OrganizationDesign\ServiceController;
use App\Http\Controllers\OrganizationDesign\StructureController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Organization Design & Workforce Planning
| (docs/organization-design-workforce-planning-plan.md)
|--------------------------------------------------------------------------
| Middleware authenticates; every action is authorized again by policy
| (permission) and OrganizationScopeService (organization scope).
*/

Route::middleware(['auth', 'verified', 'mfa', 'force.password', 'admin.access'])
    ->prefix('organization-design')->name('organization-design.')
    ->group(function (): void {
        // Phase 1: mandates, powers, functions.
        Route::get('/mandates', [MandateController::class, 'index'])->name('mandates.index');
        Route::post('/mandates', [MandateController::class, 'store'])->middleware('throttle:60,1')->name('mandates.store');
        Route::get('/mandates/{mandate}', [MandateController::class, 'show'])->whereUuid('mandate')->name('mandates.show');
        Route::put('/mandates/{mandate}', [MandateController::class, 'update'])->whereUuid('mandate')->name('mandates.update');
        Route::post('/mandates/{mandate}/versions', [MandateController::class, 'storeVersion'])->whereUuid('mandate')->name('mandates.versions.store');
        Route::post('/mandates/{mandate}/publish', [MandateController::class, 'publish'])->whereUuid('mandate')->name('mandates.publish');
        Route::post('/mandates/{mandate}/cancel', [MandateController::class, 'cancel'])->whereUuid('mandate')->name('mandates.cancel');

        Route::post('/mandates/{mandate}/powers', [FunctionController::class, 'storePower'])->whereUuid('mandate')->name('mandates.powers.store');
        Route::put('/powers/{power}', [FunctionController::class, 'updatePower'])->whereUuid('power')->name('powers.update');
        Route::delete('/powers/{power}', [FunctionController::class, 'destroyPower'])->whereUuid('power')->name('powers.destroy');

        Route::get('/functions', [FunctionController::class, 'index'])->name('functions.index');
        Route::post('/mandates/{mandate}/functions', [FunctionController::class, 'store'])->whereUuid('mandate')->name('mandates.functions.store');
        Route::put('/functions/{function}', [FunctionController::class, 'update'])->whereUuid('function')->name('functions.update');
        Route::post('/functions/{function}/move', [FunctionController::class, 'move'])->whereUuid('function')->name('functions.move');
        Route::delete('/functions/{function}', [FunctionController::class, 'destroy'])->whereUuid('function')->name('functions.destroy');

        // Phase 2: service catalog and classifications.
        Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
        Route::post('/services', [ServiceController::class, 'store'])->middleware('throttle:60,1')->name('services.store');
        Route::get('/services/{service}', [ServiceController::class, 'show'])->whereUuid('service')->name('services.show');
        Route::put('/services/{service}', [ServiceController::class, 'update'])->whereUuid('service')->name('services.update');
        Route::post('/services/{service}/versions', [ServiceController::class, 'storeVersion'])->whereUuid('service')->name('services.versions.store');
        Route::post('/services/{service}/publish', [ServiceController::class, 'publish'])->whereUuid('service')->name('services.publish');
        Route::post('/services/{service}/cancel', [ServiceController::class, 'cancel'])->whereUuid('service')->name('services.cancel');

        Route::get('/classifications', [ClassificationController::class, 'index'])->name('classifications.index');
        Route::post('/classifications', [ClassificationController::class, 'store'])->name('classifications.store');
        Route::put('/classifications/{classification}', [ClassificationController::class, 'update'])->whereUuid('classification')->name('classifications.update');
        Route::post('/classifications/import', [ClassificationController::class, 'import'])->middleware('throttle:10,1')->name('classifications.import');

        // Phase 3: process architecture, typed I/O rows, standards on master tasks, Daily Work bridge.
        Route::get('/processes', [ProcessController::class, 'index'])->name('processes.index');
        Route::post('/processes', [ProcessController::class, 'store'])->middleware('throttle:60,1')->name('processes.store');
        Route::get('/processes/{process}', [ProcessController::class, 'show'])->whereUuid('process')->name('processes.show');
        Route::put('/processes/{process}', [ProcessController::class, 'update'])->whereUuid('process')->name('processes.update');
        Route::post('/processes/{process}/versions', [ProcessController::class, 'storeVersion'])->whereUuid('process')->name('processes.versions.store');
        Route::post('/processes/{process}/publish', [ProcessController::class, 'publish'])->whereUuid('process')->name('processes.publish');
        Route::post('/processes/{process}/cancel', [ProcessController::class, 'cancel'])->whereUuid('process')->name('processes.cancel');

        Route::post('/processes/{process}/activities', [ProcessDesignController::class, 'storeActivity'])->whereUuid('process')->name('processes.activities.store');
        Route::put('/activities/{activity}', [ProcessDesignController::class, 'updateActivity'])->whereUuid('activity')->name('activities.update');
        Route::delete('/activities/{activity}', [ProcessDesignController::class, 'destroyActivity'])->whereUuid('activity')->name('activities.destroy');
        Route::post('/activities/{activity}/tasks', [ProcessDesignController::class, 'storeTask'])->whereUuid('activity')->name('activities.tasks.store');
        Route::put('/tasks/{task}', [ProcessDesignController::class, 'updateTask'])->whereUuid('task')->name('tasks.update');
        Route::delete('/tasks/{task}', [ProcessDesignController::class, 'destroyTask'])->whereUuid('task')->name('tasks.destroy');
        Route::put('/io/{subjectType}/{subject}', [ProcessDesignController::class, 'syncIo'])->whereIn('subjectType', ['process', 'activity', 'task'])->whereUuid('subject')->name('io.sync');

        Route::post('/tasks/{task}/standards', [ProcessDesignController::class, 'storeTaskStandard'])->whereUuid('task')->name('tasks.standards.store');
        Route::post('/activities/{activity}/standards', [ProcessDesignController::class, 'storeActivityStandard'])->whereUuid('activity')->name('activities.standards.store');
        Route::put('/standards/{standard}', [ProcessDesignController::class, 'updateStandard'])->whereUuid('standard')->name('standards.update');
        Route::delete('/standards/{standard}', [ProcessDesignController::class, 'destroyStandard'])->whereUuid('standard')->name('standards.destroy');
        Route::post('/standards/{standard}/approve', [ProcessDesignController::class, 'approveStandard'])->whereUuid('standard')->name('standards.approve');
        Route::post('/standards/{standard}/retire', [ProcessDesignController::class, 'retireStandard'])->whereUuid('standard')->name('standards.retire');

        Route::put('/position-tasks/{positionTask}/link', [ProcessDesignController::class, 'linkPositionTask'])->whereUuid('positionTask')->name('position-tasks.link');

        // Phase 4: professions, the competency catalog and the requirements of activities and tasks.
        Route::put('/requirements/{subjectType}/{subject}', [RequirementController::class, 'sync'])->whereIn('subjectType', ['activity', 'task'])->whereUuid('subject')->name('requirements.sync');

        Route::get('/professions', [ProfessionController::class, 'index'])->name('professions.index');
        Route::post('/professions', [ProfessionController::class, 'store'])->name('professions.store');
        Route::put('/professions/{profession}', [ProfessionController::class, 'update'])->whereUuid('profession')->name('professions.update');
        Route::post('/professions/{profession}/specializations', [ProfessionController::class, 'storeSpecialization'])->whereUuid('profession')->name('professions.specializations.store');
        Route::put('/specializations/{specialization}', [ProfessionController::class, 'updateSpecialization'])->whereUuid('specialization')->name('specializations.update');

        Route::get('/competency-catalog', [CompetencyCatalogController::class, 'index'])->name('competency-catalog.index');
        Route::post('/competency-types', [CompetencyCatalogController::class, 'storeType'])->name('competency-types.store');
        Route::put('/competency-types/{type}', [CompetencyCatalogController::class, 'updateType'])->whereUuid('type')->name('competency-types.update');
        Route::post('/competency-levels', [CompetencyCatalogController::class, 'storeLevel'])->name('competency-levels.store');
        Route::put('/competency-levels/{level}', [CompetencyCatalogController::class, 'updateLevel'])->whereUuid('level')->name('competency-levels.update');
        Route::post('/education-levels', [CompetencyCatalogController::class, 'storeEducationLevel'])->name('education-levels.store');
        Route::put('/education-levels/{educationLevel}', [CompetencyCatalogController::class, 'updateEducationLevel'])->whereUuid('educationLevel')->name('education-levels.update');
        Route::post('/competency-frameworks/{framework}/competencies', [CompetencyCatalogController::class, 'storeCompetency'])->whereUuid('framework')->name('competencies.store');
        Route::put('/competencies/{competency}', [CompetencyCatalogController::class, 'updateCompetency'])->whereUuid('competency')->name('competencies.update');

        // Phase 5: design studies, unit proposals, responsibility mapping.
        Route::get('/studies', [DesignStudyController::class, 'index'])->name('studies.index');
        Route::post('/studies', [DesignStudyController::class, 'store'])->middleware('throttle:60,1')->name('studies.store');
        Route::get('/studies/{study}', [DesignStudyController::class, 'show'])->whereUuid('study')->name('studies.show');
        Route::put('/studies/{study}', [DesignStudyController::class, 'update'])->whereUuid('study')->name('studies.update');
        Route::post('/studies/{study}/submit', [DesignStudyController::class, 'submit'])->whereUuid('study')->name('studies.submit');
        Route::post('/studies/{study}/return', [DesignStudyController::class, 'returnToDraft'])->whereUuid('study')->name('studies.return');
        Route::post('/studies/{study}/approve', [DesignStudyController::class, 'approve'])->whereUuid('study')->name('studies.approve');
        Route::post('/studies/{study}/cancel', [DesignStudyController::class, 'cancel'])->whereUuid('study')->name('studies.cancel');
        Route::post('/studies/{study}/generate', [DesignStudyController::class, 'generate'])->whereUuid('study')->name('studies.generate');
        Route::post('/studies/{study}/adopt', [DesignStudyController::class, 'adopt'])->whereUuid('study')->name('studies.adopt');
        Route::post('/studies/{study}/proposals', [DesignStudyController::class, 'storeProposal'])->whereUuid('study')->name('studies.proposals.store');
        Route::put('/proposals/{proposal}', [DesignStudyController::class, 'updateProposal'])->whereUuid('proposal')->name('proposals.update');
        Route::delete('/proposals/{proposal}', [DesignStudyController::class, 'destroyProposal'])->whereUuid('proposal')->name('proposals.destroy');

        Route::get('/responsibilities', [ResponsibilityController::class, 'index'])->name('responsibilities.index');

        // Phase 6: structure validation, functional overlay, published hierarchy versions.
        Route::get('/structure', [StructureController::class, 'index'])->name('structure.index');

        // Phase 7: methodologies, manpower plans, lines, requirements and implementation.
        Route::get('/methodologies', [MethodologyController::class, 'index'])->name('methodologies.index');
        Route::post('/methodologies', [MethodologyController::class, 'store'])->name('methodologies.store');
        Route::put('/methodologies/{methodology}', [MethodologyController::class, 'update'])->whereUuid('methodology')->name('methodologies.update');
        Route::post('/methodologies/{methodology}/versions', [MethodologyController::class, 'storeVersion'])->whereUuid('methodology')->name('methodologies.versions.store');
        Route::post('/methodologies/{methodology}/publish', [MethodologyController::class, 'publish'])->whereUuid('methodology')->name('methodologies.publish');

        Route::get('/manpower-plans', [ManpowerPlanController::class, 'index'])->name('manpower-plans.index');
        Route::post('/manpower-plans', [ManpowerPlanController::class, 'store'])->middleware('throttle:60,1')->name('manpower-plans.store');
        Route::get('/manpower-plans/{plan}', [ManpowerPlanController::class, 'show'])->whereUuid('plan')->name('manpower-plans.show');
        Route::put('/manpower-plans/{plan}', [ManpowerPlanController::class, 'update'])->whereUuid('plan')->name('manpower-plans.update');
        Route::post('/manpower-plans/{plan}/submit', [ManpowerPlanController::class, 'submit'])->whereUuid('plan')->name('manpower-plans.submit');
        Route::post('/manpower-plans/{plan}/return', [ManpowerPlanController::class, 'returnToDraft'])->whereUuid('plan')->name('manpower-plans.return');
        Route::post('/manpower-plans/{plan}/approve', [ManpowerPlanController::class, 'approve'])->whereUuid('plan')->name('manpower-plans.approve');
        Route::post('/manpower-plans/{plan}/cancel', [ManpowerPlanController::class, 'cancel'])->whereUuid('plan')->name('manpower-plans.cancel');
        Route::post('/manpower-plans/{plan}/implement', [ManpowerPlanController::class, 'implement'])->whereUuid('plan')->name('manpower-plans.implement');
        Route::post('/manpower-plans/{plan}/calculate', [ManpowerPlanController::class, 'calculateAll'])->whereUuid('plan')->name('manpower-plans.calculate');
        Route::post('/manpower-plans/{plan}/lines', [ManpowerPlanController::class, 'storeLine'])->whereUuid('plan')->name('manpower-plans.lines.store');
        Route::put('/manpower-plan-lines/{line}', [ManpowerPlanController::class, 'updateLine'])->whereUuid('line')->name('manpower-plans.lines.update');
        Route::delete('/manpower-plan-lines/{line}', [ManpowerPlanController::class, 'destroyLine'])->whereUuid('line')->name('manpower-plans.lines.destroy');
        Route::post('/manpower-plan-lines/{line}/calculate', [ManpowerPlanController::class, 'calculateLine'])->whereUuid('line')->name('manpower-plans.lines.calculate');

        // Phase 8: position profiles, work links, requirements and job descriptions.
        Route::get('/position-design', [PositionDesignController::class, 'index'])->name('position-design.index');
        Route::get('/position-design/{position}', [PositionDesignController::class, 'show'])->whereUuid('position')->name('position-design.show');
        Route::post('/position-design/{position}/profiles', [PositionDesignController::class, 'storeProfile'])->whereUuid('position')->name('position-profiles.store');
        Route::put('/position-profiles/{profile}', [PositionDesignController::class, 'updateProfile'])->whereUuid('profile')->name('position-profiles.update');
        Route::post('/position-profiles/{profile}/versions', [PositionDesignController::class, 'storeProfileVersion'])->whereUuid('profile')->name('position-profiles.versions.store');
        Route::post('/position-profiles/{profile}/publish', [PositionDesignController::class, 'publishProfile'])->whereUuid('profile')->name('position-profiles.publish');
        Route::post('/position-profiles/{profile}/cancel', [PositionDesignController::class, 'cancelProfile'])->whereUuid('profile')->name('position-profiles.cancel');
        Route::put('/position-design/{position}/work-links', [PositionDesignController::class, 'syncWorkLinks'])->whereUuid('position')->name('position-work-links.sync');
        Route::put('/position-design/{position}/requirements', [PositionDesignController::class, 'syncRequirements'])->whereUuid('position')->name('position-requirements.sync');
        Route::post('/position-design/{position}/requirements/derive', [PositionDesignController::class, 'deriveRequirements'])->whereUuid('position')->name('position-requirements.derive');

        Route::post('/position-design/{position}/job-descriptions', [JobDescriptionController::class, 'store'])->whereUuid('position')->name('job-descriptions.store');
        Route::get('/job-descriptions/{jobDescription}', [JobDescriptionController::class, 'show'])->whereUuid('jobDescription')->name('job-descriptions.show');
        Route::put('/job-descriptions/{jobDescription}/sections/{sectionKey}', [JobDescriptionController::class, 'updateSection'])->whereUuid('jobDescription')->where('sectionKey', '[a-z_]+')->name('job-descriptions.sections.update');
        Route::post('/job-descriptions/{jobDescription}/regenerate', [JobDescriptionController::class, 'regenerate'])->whereUuid('jobDescription')->name('job-descriptions.regenerate');
        Route::post('/job-descriptions/{jobDescription}/submit', [JobDescriptionController::class, 'submit'])->whereUuid('jobDescription')->name('job-descriptions.submit');
        Route::post('/job-descriptions/{jobDescription}/return', [JobDescriptionController::class, 'returnToDraft'])->whereUuid('jobDescription')->name('job-descriptions.return');
        Route::post('/job-descriptions/{jobDescription}/approve', [JobDescriptionController::class, 'approve'])->whereUuid('jobDescription')->name('job-descriptions.approve');
        Route::post('/job-descriptions/{jobDescription}/publish', [JobDescriptionController::class, 'publish'])->whereUuid('jobDescription')->name('job-descriptions.publish');
        Route::post('/job-descriptions/{jobDescription}/cancel', [JobDescriptionController::class, 'cancel'])->whereUuid('jobDescription')->name('job-descriptions.cancel');
        Route::post('/job-descriptions/{jobDescription}/versions', [JobDescriptionController::class, 'storeVersion'])->whereUuid('jobDescription')->name('job-descriptions.versions.store');

        // Phase 9: reports, traceability, gaps and queued exports.
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::post('/reports/exports', [ReportController::class, 'export'])->middleware('throttle:30,1')->name('reports.export');
        Route::get('/reports/exports/{export}/download', [ReportController::class, 'download'])->whereUuid('export')->name('reports.exports.download');
        Route::put('/responsibilities/{subjectType}/{subject}', [ResponsibilityController::class, 'sync'])->whereIn('subjectType', ['unit', 'proposal'])->whereUuid('subject')->name('responsibilities.sync');
        Route::post('/responsibility-types', [ResponsibilityController::class, 'storeType'])->name('responsibility-types.store');
        Route::put('/responsibility-types/{responsibilityType}', [ResponsibilityController::class, 'updateType'])->whereUuid('responsibilityType')->name('responsibility-types.update');
    });
