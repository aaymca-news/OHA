<?php

use App\Http\Controllers\Admin\MovementController as AdminMovementController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ArtefactController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoAccountController;
use App\Http\Controllers\DemoShowcaseController;
use App\Http\Controllers\DemoSignInController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\FollowUpController;
use App\Http\Controllers\GapController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\MovementController;
use App\Http\Controllers\MyWorkController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportDraftController;
use App\Http\Controllers\ReportFixController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\TimelineController;
use App\Models\Movement;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// The public front page; anyone signed in goes straight to their dashboard.
Route::get('/', LandingController::class)->middleware('guest')->name('home');

// Sign-in and password reset routes are registered by Fortify.

// The demo site only: a visitor's account and their own demo (404 anywhere else, as on
// live). At most 30 registrations an hour from one address: a workshop room shares one.
Route::prefix('demo/account')->name('demo.')->controller(DemoAccountController::class)->group(function () {
    Route::get('register', 'create')->name('register');
    Route::post('register', 'store')->middleware('throttle:30,60')->name('register.store');
    Route::get('sign-in', 'showSignIn')->name('sign-in.show');
    Route::post('sign-in', 'signIn')->middleware('throttle:10,1')->name('sign-in.store');
    Route::post('sign-out', 'signOut')->name('sign-out');
    Route::post('choose', 'choose')->name('choose');
    Route::post('start-over', 'startOver')->middleware('throttle:30,60')->name('start-over');
    Route::delete('/', 'destroy')->name('destroy');
    Route::get('forgot-password', 'forgot')->name('password.request');
    Route::post('forgot-password', 'sendResetLink')->middleware('throttle:5,1')->name('password.email');
    Route::get('reset-password/{token}', 'showReset')->name('password.reset');
    Route::post('reset-password', 'reset')->middleware('throttle:10,1')->name('password.update');
});
// The showcase, where the demo's Super Administrator prepares the work every new copy starts from.
Route::get('demo/showcase', [DemoAccountController::class, 'showcase'])->name('demo.showcase');
Route::post('demo/showcase/leave', [DemoAccountController::class, 'leaveShowcase'])->name('demo.showcase.leave');
// The Super Administrator into a visitor's demo, to act on what they sent for approval, and back.
Route::get('demo/showcase/visitors/{visitor}/open', [DemoShowcaseController::class, 'open'])->middleware('auth')->name('demo.showcase.open');
Route::get('demo/showcase/visitors/{visitor}/enter', [DemoShowcaseController::class, 'enter'])->middleware('throttle:20,1')->name('demo.showcase.enter');
Route::post('demo/showcase/back', [DemoShowcaseController::class, 'back'])->name('demo.showcase.back');

Route::middleware('guest')->group(function () {
    // The demo site only: "Choose who to be", one click per person in the visitor's demo.
    // Anywhere else (config oha.demo off, as on the live site) it answers 404.
    Route::post('demo/as', DemoSignInController::class)->middleware('throttle:60,1')->name('demo.sign-in');

    Route::get('invitation/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('invitation', [InvitationController::class, 'store'])->middleware('throttle:10,1')->name('invitation.store');
});

Route::middleware('auth')->group(function () {
    Route::get('security', [SecurityController::class, 'show'])->name('security.show');
    Route::delete('security/other-sessions', [SecurityController::class, 'signOutOtherSessions'])->name('security.other-sessions');
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('profile/photo', [ProfileController::class, 'updatePhoto'])->middleware('throttle:10,1')->name('profile.photo');
    Route::delete('profile/photo', [ProfileController::class, 'removePhoto'])->name('profile.photo.remove');
    Route::get('people/{user}/photo', [ProfileController::class, 'photo'])->name('avatars.show');

    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('my-work', MyWorkController::class)->name('my-work');
    Route::get('search', SearchController::class)->name('search');
    Route::get('timelines', [TimelineController::class, 'index'])->name('timelines');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read', [NotificationController::class, 'markAllRead'])->name('notifications.read');

    Route::get('movements', [MovementController::class, 'index'])->name('movements.index');
    Route::get('movements/{movement}', [MovementController::class, 'show'])->name('movements.show');
    Route::post('movements/{movement}/assessments', [AssessmentController::class, 'store'])->name('assessments.store');
    Route::put('movements/{movement}/assessors', [MovementController::class, 'assignAssessors'])->name('movements.assessors');

    Route::get('assessments', [AssessmentController::class, 'index'])->name('assessments.index');
    Route::get('assessments/{assessment}', [AssessmentController::class, 'show'])->name('assessments.show');
    Route::post('assessments/{assessment}/hand-back', [AssessmentController::class, 'handBack'])->name('assessments.hand-back');
    Route::delete('assessments/{assessment}', [AssessmentController::class, 'destroy'])->name('assessments.destroy');

    // Workflow steps. Each action checks its own policy and says why if it refuses.
    Route::prefix('artefacts/{artefact}')->name('artefacts.')->controller(ArtefactController::class)->group(function () {
        Route::post('upload', 'upload')->name('upload');
        Route::put('drive', 'linkDrive')->name('drive');
        Route::post('drive/sync', 'syncDrive')->middleware('throttle:6,1')->name('drive.sync');
        Route::post('submit', 'submit')->name('submit');
        Route::post('approve', 'approve')->name('approve');
        Route::post('send-back', 'sendBack')->name('send-back');
        Route::post('versions', 'uploadVersion')->name('versions');
        Route::post('sign', 'sign')->name('sign');
    });
    Route::delete('versions/{document}', [ArtefactController::class, 'withdraw'])->name('versions.withdraw');

    // The report drafted from the OHA form, corrected on screen section by section.
    Route::prefix('artefacts/{artefact}/draft')->name('report-draft.')->controller(ReportDraftController::class)->group(function () {
        Route::get('/', 'show')->name('show');
        Route::post('/', 'start')->name('start');
        Route::delete('/', 'destroy')->name('destroy');
        Route::post('facts', 'facts')->name('facts');
        Route::post('word', 'make')->name('make');
        Route::post('sections', 'add')->name('add');
        Route::put('sections/{key}', 'save')->where('key', '[a-z0-9_:]+')->name('save');
        Route::post('sections/{key}/rewrite', 'rewrite')->where('key', '[a-z0-9_:]+')->name('rewrite');
        Route::delete('sections/{key}', 'remove')->where('key', '[a-z0-9_:]+')->name('remove');
    });

    Route::post('findings/{finding}/resolve', [GapController::class, 'resolve'])->name('findings.resolve');
    Route::post('findings/{finding}/reopen', [GapController::class, 'reopen'])->name('findings.reopen');
    // What the OHA form lacks, typed in the platform; and an upload made by mistake, deleted.
    Route::post('findings/{finding}/answer', [GapController::class, 'answer'])->name('findings.answer');
    Route::delete('form-uploads/{formUpload}/answers', [GapController::class, 'withdraw'])->name('form-uploads.withdraw');
    Route::delete('form-uploads/{formUpload}', [GapController::class, 'destroyUpload'])->name('form-uploads.destroy');

    // What the report check found, fixed in the report (a new version) or marked as reviewed.
    Route::prefix('artefacts/{artefact}/report-check')->name('report-check.')->controller(ReportFixController::class)->group(function () {
        Route::post('fix', 'fix')->name('fix');
        Route::post('from-form', 'fromForm')->name('from-form');
        Route::post('review', 'review')->name('review');
        Route::delete('review', 'reopen')->name('reopen');
    });

    Route::get('resources', ResourceController::class)->name('resources');

    // Each movement's evidence library: supporting documents linked to the form and the ODP.
    Route::get('resources/{movement}', [EvidenceController::class, 'index'])->name('evidence.index');
    Route::post('resources/{movement}/evidence', [EvidenceController::class, 'store'])->middleware('throttle:30,1')->name('evidence.store');
    Route::get('evidence/{evidence}/download', [EvidenceController::class, 'download'])->name('evidence.download');
    Route::put('evidence/{evidence}/sharing', [EvidenceController::class, 'share'])->name('evidence.share');
    Route::put('evidence/{evidence}/folder', [EvidenceController::class, 'move'])->name('evidence.move');
    Route::delete('evidence/{evidence}', [EvidenceController::class, 'destroy'])->name('evidence.destroy');

    Route::put('assessments/{assessment}/timeline/gate', [TimelineController::class, 'setGate'])->name('timeline.gate');
    Route::post('assessments/{assessment}/timeline/steps', [TimelineController::class, 'addStep'])->name('timeline.steps.store');
    Route::put('timeline/steps/{step}', [TimelineController::class, 'updateStep'])->name('timeline.steps.update');
    Route::delete('timeline/steps/{step}', [TimelineController::class, 'removeStep'])->name('timeline.steps.destroy');
    // The ODP's tasks: read from the ODP, typed in when it cannot be read, and corrected.
    Route::post('assessments/{assessment}/odp-tasks', [TimelineController::class, 'addTask'])->name('timeline.tasks.store');
    Route::put('odp-tasks/{odpTask}', [TimelineController::class, 'updateTask'])->name('timeline.tasks.update');
    Route::delete('odp-tasks/{odpTask}', [TimelineController::class, 'removeTask'])->name('timeline.tasks.destroy');

    // Stage 2, ODP follow-up: each task's progress, reported and confirmed.
    Route::get('assessments/{assessment}/follow-up', [FollowUpController::class, 'show'])->name('follow-up.show');
    Route::post('odp-tasks/{odpTask}/progress', [FollowUpController::class, 'progress'])->name('follow-up.progress');
    Route::post('odp-tasks/{odpTask}/confirm', [FollowUpController::class, 'confirm'])->name('follow-up.confirm');
    Route::post('assessments/{assessment}/follow-up/meetings', [FollowUpController::class, 'record'])->name('follow-up.meetings.store');
    Route::put('assessments/{assessment}/follow-up/pause', [FollowUpController::class, 'pause'])->name('follow-up.pause');
    Route::post('assessments/{assessment}/follow-up/review', [FollowUpController::class, 'review'])->name('follow-up.review');
    Route::post('assessments/{assessment}/follow-up/carry', [FollowUpController::class, 'carry'])->name('follow-up.carry');

    Route::prefix('downloads')->name('downloads.')->group(function () {
        Route::get('blank-oha-form', [DownloadController::class, 'blankForm'])->name('blank-form');
        Route::get('alliance-scores', [DownloadController::class, 'scores'])->middleware('throttle:10,1')->name('scores');
        Route::get('zip/assessments/{assessment}', [DownloadController::class, 'assessmentZip'])->middleware('throttle:10,1')->name('zip.assessment');
        Route::get('zip/movements/{movement}', [DownloadController::class, 'movementZip'])->middleware('throttle:10,1')->name('zip.movement');
        Route::get('report-template', [DownloadController::class, 'reportTemplate'])->name('report-template');
        Route::get('odp-template', [DownloadController::class, 'odpTemplate'])->name('odp-template');
        Route::get('forms/{formUpload}', [DownloadController::class, 'form'])->name('form')->can('download', 'formUpload');
        Route::get('documents/{document}', [DownloadController::class, 'document'])->name('document')->can('download', 'document');
        Route::get('documents/{document}/preview', [DownloadController::class, 'preview'])->name('preview')->can('download', 'document');
        Route::get('signatures/{signature}', [DownloadController::class, 'signature'])->name('signature');
    });

    Route::prefix('admin/users')->name('admin.users.')->middleware('can:manage,'.User::class)->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('invite', [UserController::class, 'create'])->name('create');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('{user}', [UserController::class, 'edit'])->name('edit');
        Route::put('{user}', [UserController::class, 'update'])->name('update');
        Route::put('{user}/role', [UserController::class, 'updateRole'])->name('role');
        Route::post('{user}/deactivate', [UserController::class, 'deactivate'])->name('deactivate');
        Route::post('{user}/reactivate', [UserController::class, 'reactivate'])->name('reactivate');
        Route::post('{user}/invitation', [UserController::class, 'resendInvitation'])->name('invitation');
    });

    Route::prefix('admin/movements')->name('admin.movements.')->group(function () {
        Route::get('add', [AdminMovementController::class, 'create'])->name('create')->can('create', Movement::class);
        Route::post('/', [AdminMovementController::class, 'store'])->name('store')->can('create', Movement::class);
        Route::get('{movement}/edit', [AdminMovementController::class, 'edit'])->name('edit')->can('update', 'movement');
        Route::put('{movement}', [AdminMovementController::class, 'update'])->name('update')->can('update', 'movement');
    });

    // Every shared component on one page, to build and check them in one place. On the
    // developer's own machine only: the route does not exist on the live site.
    if (app()->environment('local')) {
        Route::view('dev/components', 'dev.components')->name('dev.components');
    }
});
