<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Dashboard (home)
Route::get('/', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Tasks
Route::get('/tasks', function () {
    return Inertia::render('Tasks');
})->middleware(['auth'])->name('tasks');

// Apps
Route::get('/apps', function () {
    return Inertia::render('Apps');
})->middleware(['auth'])->name('apps');

// Chats
Route::get('/chats', function () {
    return Inertia::render('Chats');
})->middleware(['auth'])->name('chats');

// Users
Route::get('/users', function () {
    return Inertia::render('Users');
})->middleware(['auth'])->name('users');

// Help Center
Route::get('/help-center', function () {
    return Inertia::render('HelpCenter');
})->middleware(['auth'])->name('help-center');

// Settings
Route::prefix('settings')->middleware(['auth'])->group(function () {
    Route::get('/', function () {
        return Inertia::render('Settings/Index');
    })->name('settings');

    Route::get('/account', function () {
        return Inertia::render('Settings/Account');
    })->name('settings.account');

    Route::get('/appearance', function () {
        return Inertia::render('Settings/Appearance');
    })->name('settings.appearance');

    Route::get('/notifications', function () {
        return Inertia::render('Settings/Notifications');
    })->name('settings.notifications');

    Route::get('/display', function () {
        return Inertia::render('Settings/Display');
    })->name('settings.display');
});

// Error pages
Route::prefix('errors')->group(function () {
    Route::get('/unauthorized', function () {
        return Inertia::render('Errors/Unauthorized');
    })->name('errors.unauthorized');

    Route::get('/forbidden', function () {
        return Inertia::render('Errors/Forbidden');
    })->name('errors.forbidden');

    Route::get('/not-found', function () {
        return Inertia::render('Errors/NotFound');
    })->name('errors.not-found');

    Route::get('/internal-server-error', function () {
        return Inertia::render('Errors/InternalServerError');
    })->name('errors.internal-server-error');

    Route::get('/maintenance-error', function () {
        return Inertia::render('Errors/MaintenanceError');
    })->name('errors.maintenance-error');
});

require __DIR__.'/auth.php';
