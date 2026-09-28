<?php

namespace App\Actions;

use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class CloneRepository
{
    public function __construct(private InspectFolder $inspect) {}

    public function handle(string $remoteUrl, string $parent, string $provider, #[SensitiveParameter] string $token): array
    {
        $parent = str_contains($parent, "\0") ? false : realpath($parent);
        if (! $parent || ! is_dir($parent) || ! is_writable($parent)) {
            throw ValidationException::withMessages(['folder' => 'Choose a folder you can write to.']);
        }
        $name = preg_replace('/\.git$/i', '', basename((string) parse_url($remoteUrl, PHP_URL_PATH)));
        if (! $name || in_array($name, ['.', '..'], true) || ! preg_match('/\A[A-Za-z0-9_.-]+\z/D', $name)) {
            throw ValidationException::withMessages(['repository' => 'The selected repository has an invalid name.']);
        }
        $destination = $parent.DIRECTORY_SEPARATOR.$name;
        if (! @mkdir($destination, 0700)) {
            throw ValidationException::withMessages(['repository' => 'A folder with this repository name already exists.']);
        }

        try {
            $git = (new ExecutableFinder)->find('git');
            if (PHP_OS_FAMILY === 'Darwin' && $git === '/usr/bin/git' && is_executable('/Library/Developer/CommandLineTools/usr/bin/git')) {
                $git = '/Library/Developer/CommandLineTools/usr/bin/git';
            }
            if (! $git) {
                throw new \RuntimeException;
            }
            $environment = [];
            foreach (array_keys(getenv()) as $key) {
                if (preg_match('/^(GIT_|DYLD_|LD_|BASH_ENV$|ENV$)/i', $key)) {
                    $environment[$key] = false;
                }
            }
            $environment = array_replace($environment, [
                'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'GIT_TERMINAL_PROMPT' => '0',
                'GIT_CONFIG_COUNT' => $token === '' ? '2' : '3',
                'GIT_CONFIG_KEY_0' => 'credential.helper', 'GIT_CONFIG_VALUE_0' => '',
                'GIT_CONFIG_KEY_1' => 'http.followRedirects', 'GIT_CONFIG_VALUE_1' => 'false',
            ]);
            if ($token !== '') {
                $environment['GIT_CONFIG_KEY_2'] = 'http.'.$remoteUrl.'.extraHeader';
                $environment['GIT_CONFIG_VALUE_2'] = 'Authorization: Basic '.base64_encode(($provider === 'github' ? 'x-access-token' : 'oauth2').':'.$token);
            }
            $process = new Process([$git, '-c', 'core.hooksPath='.$environment['GIT_CONFIG_GLOBAL'], 'clone', '--quiet', '--', $remoteUrl, $destination], $parent, $environment, null, 300);
            $process->disableOutput();
            $process->run();
            if (! $process->isSuccessful()) {
                throw new \RuntimeException;
            }

            return $this->inspect->handle($destination);
        } catch (Throwable) {
            File::deleteDirectory($destination);
            throw ValidationException::withMessages(['repository' => 'Repository could not be cloned. Check this connection and try again.']);
        }
    }
}
