<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::livewire('/', 'auth.login')
        ->name('login');
});

Route::middleware(['auth', 'admin.access'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {

        Route::livewire('/', 'admin.dashboard')
            ->name('dashboard');

        Route::livewire('/discovery', 'admin.discovery.index')
            ->name('discovery.index');

        Route::livewire('/discovery/create', 'admin.discovery.create')
            ->name('discovery.create');

        Route::livewire('/sources', 'admin.sources.index')
            ->name('sources.index');

        Route::livewire('/sources/create', 'admin.sources.create')
            ->name('sources.create');

        Route::livewire('/sources/{source}/edit', 'admin.sources.edit')
            ->name('sources.edit');

        Route::livewire(
            '/generation-profiles',
            'admin.generation-profiles.index'
        )->name('generation-profiles.index');

        Route::livewire(
            '/generation-profiles/create',
            'admin.generation-profiles.create'
        )->name('generation-profiles.create');

        Route::livewire(
            '/generation-profiles/{profile}/edit',
            'admin.generation-profiles.edit'
        )->name('generation-profiles.edit');

        Route::middleware('can:manage-users')->group(function () {
            Route::livewire('/users', 'admin.users.index')
                ->name('users.index');

            Route::livewire('/users/create', 'admin.users.create')
                ->name('users.create');

            Route::livewire('/users/{user}/edit', 'admin.users.edit')
                ->name('users.edit');
        });

        Route::post('/logout', function (Request $request) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        })->name('logout');
    });
