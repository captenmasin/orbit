<?php

namespace App\Providers;

use App\SecretVault;
use App\WorkspacePreferences;
use Illuminate\Support\Facades\Cache;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Enums\SystemThemesEnum;
use Native\Desktop\Facades\ContextMenu;
use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\System;
use Native\Desktop\Facades\Window;
use Throwable;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        app(SecretVault::class)->revokeUnlocks();
        if ($owner = Cache::pull('orbit-update-restart-owner')) {
            Cache::restoreLock('orbit-workspace-write', $owner)->release();
        }
        try {
            System::theme(SystemThemesEnum::from(app(WorkspacePreferences::class)->get('appearance.theme')));
        } catch (Throwable) {
            // The page can apply its saved appearance while the native theme bridge recovers.
        }
        Menu::create(
            Menu::app(),
            Menu::file(),
            Menu::edit(),
            Menu::view(),
            Menu::make(
                Menu::label('New Project…', 'CmdOrCtrl+N')->id('new-project'),
                Menu::label('Dashboard', 'CmdOrCtrl+Shift+H')->id('dashboard'),
                Menu::label('Search Workspace…', 'CmdOrCtrl+K')->id('search'),
                Menu::separator(),
                Menu::label('Settings…', 'CmdOrCtrl+,')->id('settings'),
                Menu::label('Backups & Restore…')->id('backups'),
            )->label('Workspace'),
            Menu::make(
                Menu::label('Connections…')->id('connections'),
                Menu::label('Tools & Runtimes…')->id('tools'),
            )->label('Tools'),
            Menu::window(),
            Menu::make(
                Menu::label('About Orbit & Updates…')->id('about'),
            )->label('Help'),
        );
        ContextMenu::register(Menu::make());
        $url = str_replace('://127.0.0.1', '://localhost', route('startup'));
        $window = Window::open()->url($url)->title('Orbit')->width(1180)->height(850)->minWidth(600)->minHeight(600);
        if (PHP_OS_FAMILY === 'Darwin') {
            $window->titleBarHiddenInset();
        }
    }

    /**
     * Return an array of php.ini directives to be set.
     * Native file dialogs wait for user input beyond PHP's request deadline.
     */
    public function phpIni(): array
    {
        return [
            'max_execution_time' => '0',
            'upload_max_filesize' => '10M',
            'max_file_uploads' => '100',
            'post_max_size' => '1100M',
        ];
    }
}
