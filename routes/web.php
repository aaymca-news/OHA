<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ArtefactController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\GapController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MovementController;
use App\Http\Controllers\MyWorkController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\TimelineController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

// Sign-in and password reset routes are registered by Fortify.

Route::middleware('guest')->group(function () {
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
        Route::post('documents', 'attach')->name('attach');
    });

    Route::post('findings/{finding}/resolve', [GapController::class, 'resolve'])->name('findings.resolve');
    Route::post('findings/{finding}/reopen', [GapController::class, 'reopen'])->name('findings.reopen');
    // What the OHA form lacks, typed in the platform; and an upload made by mistake, deleted.
    Route::post('findings/{finding}/answer', [GapController::class, 'answer'])->name('findings.answer');
    Route::delete('form-uploads/{formUpload}/answers', [GapController::class, 'withdraw'])->name('form-uploads.withdraw');
    Route::delete('form-uploads/{formUpload}', [GapController::class, 'destroyUpload'])->name('form-uploads.destroy');

    Route::get('resources', ResourceController::class)->name('resources');

    Route::put('assessments/{assessment}/timeline/gate', [TimelineController::class, 'setGate'])->name('timeline.gate');
    Route::post('assessments/{assessment}/timeline/steps', [TimelineController::class, 'addStep'])->name('timeline.steps.store');
    Route::put('timeline/steps/{step}', [TimelineController::class, 'updateStep'])->name('timeline.steps.update');
    Route::delete('timeline/steps/{step}', [TimelineController::class, 'removeStep'])->name('timeline.steps.destroy');

    Route::prefix('downloads')->name('downloads.')->group(function () {
        Route::get('blank-oha-form', [DownloadController::class, 'blankForm'])->name('blank-form');
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
});
