<?php

namespace App;

use App\Actions\ProtectCredential;
use Closure;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\AiManager;
use SensitiveParameter;

class ScratchpadAi
{
    public const PROVIDERS = ['openai', 'anthropic', 'gemini'];

    public function __construct(private WorkspacePreferences $preferences, private ProtectCredential $credentials, private AiManager $manager) {}

    /**
     * @return array{provider: ?string, model: ?string, configured: bool, providers: list<string>, default_models: array<string, string>}
     */
    public function status(): array
    {
        $config = $this->preferences->get('ai');
        $defaultModels = [];
        foreach (self::PROVIDERS as $provider) {
            $defaultModels[$provider] = $this->manager->textProvider($provider)->defaultTextModel();
        }

        return ['provider' => $config['provider'], 'model' => $config['model'], 'configured' => filled($config['credential']), 'providers' => self::PROVIDERS, 'default_models' => $defaultModels];
    }

    public function configured(Closure $operation): mixed
    {
        $config = $this->preferences->get('ai');
        if (! filled($config['credential']) || ! in_array($config['provider'], self::PROVIDERS, true) || ! filled($config['model'])) {
            throw ValidationException::withMessages(['scratchpad' => 'Configure scratchpad AI in Settings → Connections to generate actions.']);
        }

        return $this->using($config['provider'], $config['model'], $this->credentials->decrypt($config['credential']), $operation);
    }

    public function using(string $provider, string $model, #[SensitiveParameter] string $key, Closure $operation): mixed
    {
        $previous = config('ai.providers.orbit-scratchpad');
        $this->manager->purge('orbit-scratchpad');
        config(['ai.providers.orbit-scratchpad' => [...config('ai.providers.'.$provider), 'key' => $key, 'name' => 'orbit-scratchpad', 'store' => false]]);
        try {
            return $operation('orbit-scratchpad', $model);
        } finally {
            $this->manager->purge('orbit-scratchpad');
            config(['ai.providers.orbit-scratchpad' => $previous]);
        }
    }
}
