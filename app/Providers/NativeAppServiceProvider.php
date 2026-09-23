<?php

namespace App\Providers;

use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        $url = str_replace('://127.0.0.1', '://localhost', url('/'));
        Window::open()->url($url)->title('Orbit')->width(1180)->height(850)->minWidth(600)->minHeight(600);
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
