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

        // Dashboard
        Route::livewire('/', 'admin.dashboard')
            ->name('dashboard');

        // Discovery
        Route::livewire('/discovery', 'admin.discovery.index')
            ->name('discovery.index');

        Route::livewire('/discovery/create', 'admin.discovery.create')
            ->name('discovery.create');

        // Sources
        Route::livewire('/sources', 'admin.sources.index')
            ->name('sources.index');

        Route::livewire('/sources/create', 'admin.sources.create')
            ->name('sources.create');

        Route::livewire('/sources/{source}/edit', 'admin.sources.edit')
            ->name('sources.edit');

        // Generation Profiles
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

        // Events
        Route::livewire('/events', 'admin.events.index')
            ->name('events.index');

        Route::livewire('/events/create', 'admin.events.create')
            ->name('events.create');

        Route::livewire('/events/{event}/edit', 'admin.events.edit')
            ->name('events.edit');

        Route::livewire('/events/{event}', 'admin.events.show')
            ->name('events.show');

        // Markets
        Route::livewire('/markets', 'admin.markets.index')
            ->name('markets.index');

        Route::livewire('/markets/create', 'admin.markets.create')
            ->name('markets.create');

        Route::livewire('/markets/{market}/edit', 'admin.markets.edit')
            ->name('markets.edit');

        Route::livewire('/markets/{market}', 'admin.markets.show')
            ->name('markets.show');

        // Create market from an existing event
        Route::livewire(
            '/events/{event}/markets/create',
            'admin.markets.create'
        )->name('events.markets.create');

        // Users
        Route::middleware('can:manage-users')->group(function () {
            Route::livewire('/users', 'admin.users.index')
                ->name('users.index');

            Route::livewire('/users/create', 'admin.users.create')
                ->name('users.create');

            Route::livewire('/users/{user}/edit', 'admin.users.edit')
                ->name('users.edit');
        });

        // Logout
        Route::post('/logout', function (Request $request) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        })->name('logout');
    });
