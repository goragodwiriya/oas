<?php
/**
 * @filesource Gcms/Ai.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms;

/**
 * AI driver factory
 *
 * Creates the appropriate driver instance based on the provider name.
 * Provider is read from self::$cfg->ai_provider unless overridden.
 *
 * Supported providers:
 *   openai      — OpenAI (https://api.openai.com/v1)
 *   groq        — Groq free tier (https://api.groq.com/openai/v1)
 *   deepseek    — DeepSeek V4 (https://api.deepseek.com/v1, thinking mode)
 *   openrouter  — OpenRouter with free models (https://openrouter.ai/api/v1)
 *   vercel      — Vercel AI Gateway (https://ai-gateway.vercel.sh/v1)
 *   nvidia      — NVIDIA NIM API (https://integrate.api.nvidia.com/v1)
 *   ollama      — Ollama local (http://localhost:11434/v1)
 *   lmstudio    — LM Studio local (http://localhost:1234/v1)
 *   gemini      — Google Gemini native API
 *   claude      — Anthropic Claude native API
 *
 * Usage:
 *   $response = Gcms\Ai::driver()->chat([['role' => 'user', 'content' => '...']]);
 *   $response = Gcms\Ai::driver('gemini')->chat([...], ['system' => 'You are helpful.']);
 *   $response = Gcms\Ai::driver('ollama', ['model' => 'llama3', 'api_url' => 'http://localhost:11434/v1'])->chat([...]);
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Ai extends \Kotchasan\KBase
{
    /**
     * Canonical provider metadata.
     *
     * The defaults can be overridden in config via ai_default_models and
     * ai_default_api_urls. Model lists are intentionally owned by this class
     * so provider model changes are maintained in one place.
     *
     * @var array
     */
    private static $providers = [
        'openai' => [
            'text' => 'OpenAI',
            'default_model' => 'gpt-4o-mini',
            'default_api_url' => 'https://api.openai.com/v1',
            'models' => ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1', 'o4-mini'],
            'local' => false
        ],
        'gemini' => [
            'text' => 'Google Gemini',
            'default_model' => 'gemini-3-flash-preview',
            'default_api_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'models' => ['gemini-3-flash-preview', 'gemini-2.0-flash', 'gemini-2.0-flash-lite', 'gemini-1.5-flash', 'gemini-1.5-pro'],
            'local' => false
        ],
        'claude' => [
            'text' => 'Anthropic Claude',
            'default_model' => 'claude-haiku-3-5',
            'default_api_url' => 'https://api.anthropic.com/v1/messages',
            'models' => ['claude-haiku-3-5', 'claude-sonnet-4', 'claude-opus-4'],
            'local' => false
        ],
        'groq' => [
            'text' => 'Groq (free)',
            'default_model' => 'llama-3.3-70b-versatile',
            'default_api_url' => 'https://api.groq.com/openai/v1',
            'models' => ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'mixtral-8x7b-32768', 'gemma2-9b-it'],
            'local' => false
        ],
        'deepseek' => [
            'text' => 'DeepSeek',
            'default_model' => 'deepseek-v4-flash',
            'default_api_url' => 'https://api.deepseek.com/v1',
            'models' => ['deepseek-v4-flash', 'deepseek-v4-pro'],
            'thinking_efforts' => ['high', 'max'],
            'default_thinking_enabled' => 0,
            'default_reasoning_effort' => 'high',
            'local' => false
        ],
        'openrouter' => [
            'text' => 'OpenRouter (free models)',
            'default_model' => 'openrouter/auto',
            'default_api_url' => 'https://openrouter.ai/api/v1',
            'models' => ['openrouter/auto', 'openai/gpt-4o-mini', 'google/gemini-2.0-flash-001', 'anthropic/claude-3.5-haiku', 'glm-5.2'],
            'local' => false
        ],
        'vercel' => [
            'text' => 'Vercel AI Gateway',
            'default_model' => 'openai/gpt-4.1-mini',
            'default_api_url' => 'https://ai-gateway.vercel.sh/v1',
            'models' => ['openai/gpt-4.1-mini', 'openai/gpt-4o-mini', 'google/gemini-2.0-flash-001', 'anthropic/claude-3.5-haiku'],
            'local' => false
        ],
        'nvidia' => [
            'text' => 'NVIDIA NIM',
            'default_model' => 'nvidia/llama-3.1-nemotron-70b-instruct',
            'default_api_url' => 'https://integrate.api.nvidia.com/v1',
            'models' => ['nvidia/llama-3.1-nemotron-70b-instruct', 'meta/llama-3.3-70b-instruct', 'meta/llama-3.1-70b-instruct'],
            'local' => false
        ],
        'glm' => [
            'text' => 'Zhipu GLM',
            'default_model' => 'glm-5.2',
            'default_api_url' => 'https://api.z.ai/api/paas/v4',
            'models' => ['glm-5.2', 'glm-4.5-flash', 'glm-4.5-air', 'glm-4.5'],
            'thinking_efforts' => ['high', 'max'],
            'default_thinking_enabled' => 0,
            'default_reasoning_effort' => 'high',
            'local' => false
        ],
        'ollama' => [
            'text' => 'Ollama (local)',
            'default_model' => 'llama3.2',
            'default_api_url' => 'http://localhost:11434/v1',
            'models' => ['llama3.2', 'qwen2.5', 'mistral'],
            'local' => true
        ],
        'lmstudio' => [
            'text' => 'LM Studio (local)',
            'default_model' => 'llama3.2',
            'default_api_url' => 'http://localhost:1234/v1',
            'models' => ['llama3.2', 'qwen2.5', 'mistral'],
            'local' => true
        ]
    ];

    /**
     * Return provider metadata merged with config overrides.
     *
     * @param string|null $provider Provider name or null for all providers
     *
     * @return array
     */
    public static function providerDefaults($provider = null)
    {
        $providers = self::configuredProviders();
        if ($provider === null || $provider === '') {
            return $providers;
        }
        $provider = strtolower(trim($provider));

        return $providers[$provider] ?? [];
    }

    /**
     * Return provider options for form dropdowns.
     *
     * @return array
     */
    public static function providerOptions()
    {
        $options = [];
        foreach (self::configuredProviders() as $name => $item) {
            $options[] = [
                'value' => $name,
                'text' => $item['text']
            ];
        }

        return $options;
    }

    /**
     * Return normalized saved connection settings.
     *
     * @param string|null $provider Provider name or null for all providers
     *
     * @return array
     */
    public static function connectionSettings($provider = null)
    {
        $providers = self::configuredProviders();
        $stored = (new \Gcms\Ai\SettingsRepository())->connector();
        $connections = !empty($stored['ai_connections']) && is_array($stored['ai_connections'])
            ? $stored['ai_connections']
            : (!empty(self::$cfg->ai_connections) && is_array(self::$cfg->ai_connections) ? self::$cfg->ai_connections : []);

        if ($provider === null || $provider === '') {
            $result = [];
            foreach ($providers as $name => $item) {
                $saved = !empty($connections[$name]) && is_array($connections[$name]) ? $connections[$name] : [];
                $result[$name] = self::normalizeConnection($name, $saved, self::legacyConnection($name));
            }

            return $result;
        }

        $provider = strtolower(trim($provider));
        if (!isset($providers[$provider])) {
            return [];
        }

        $saved = !empty($connections[$provider]) && is_array($connections[$provider]) ? $connections[$provider] : [];

        return self::normalizeConnection($provider, $saved, self::legacyConnection($provider));
    }

    /**
     * Apply project config overrides to the canonical provider map.
     *
     * @return array
     */
    private static function configuredProviders()
    {
        $providers = self::$providers;

        if (!empty(self::$cfg->ai_default_models) && is_array(self::$cfg->ai_default_models)) {
            foreach (self::$cfg->ai_default_models as $provider => $model) {
                $provider = strtolower(trim((string) $provider));
                if (isset($providers[$provider]) && $model !== '') {
                    $providers[$provider]['default_model'] = trim((string) $model);
                }
            }
        }
        if (!empty(self::$cfg->ai_default_api_urls) && is_array(self::$cfg->ai_default_api_urls)) {
            foreach (self::$cfg->ai_default_api_urls as $provider => $apiUrl) {
                $provider = strtolower(trim((string) $provider));
                if (isset($providers[$provider]) && $apiUrl !== '') {
                    $providers[$provider]['default_api_url'] = trim((string) $apiUrl);
                }
            }
        }
        foreach ($providers as $provider => $item) {
            $models = !empty($item['models']) && is_array($item['models']) ? $item['models'] : [];
            if (!empty($item['default_model']) && !in_array($item['default_model'], $models, true)) {
                array_unshift($models, $item['default_model']);
            }
            $providers[$provider]['models'] = array_values(array_unique(array_filter($models)));
        }

        return $providers;
    }

    /**
     * Return legacy single-provider settings for backward compatibility.
     *
     * @param string $provider
     *
     * @return array
     */
    private static function legacyConnection($provider)
    {
        $stored = (new \Gcms\Ai\SettingsRepository())->connector();
        $activeProvider = !empty($stored['ai_provider'])
            ? strtolower(trim((string) $stored['ai_provider']))
            : (!empty(self::$cfg->ai_provider) ? strtolower(trim((string) self::$cfg->ai_provider)) : 'openai');
        if ($provider !== $activeProvider) {
            return [];
        }

        return [
            'api_key' => self::$cfg->ai_api_key ?? '',
            'api_url' => self::$cfg->ai_api_url ?? '',
            'model' => self::$cfg->ai_model ?? '',
            'max_tokens' => self::$cfg->ai_max_tokens ?? 1024,
            'temperature' => self::$cfg->ai_temperature ?? 0.7
        ];
    }

    /**
     * Normalize a provider connection with defaults and legacy fallback.
     *
     * @param string $provider
     * @param array  $saved
     * @param array  $legacy
     *
     * @return array
     */
    private static function normalizeConnection($provider, array $saved, array $legacy)
    {
        $defaults = self::configuredProviders()[$provider];
        $models = !empty($defaults['models']) && is_array($defaults['models']) ? $defaults['models'] : [];
        $storedModel = trim((string) ($saved['model'] ?? ''));
        $storedCustomModel = trim((string) ($saved['custom_model'] ?? ''));
        $useCustomModel = !empty($saved['use_custom_model']);

        if (!$useCustomModel && $storedModel === '' && !empty($legacy['model'])) {
            $legacyModel = trim((string) $legacy['model']);
            if (in_array($legacyModel, $models, true)) {
                $storedModel = $legacyModel;
            } elseif ($legacyModel !== '') {
                $useCustomModel = true;
                $storedCustomModel = $legacyModel;
            }
        }

        if ($useCustomModel && $storedCustomModel === '' && !empty($legacy['model'])) {
            $storedCustomModel = trim((string) $legacy['model']);
        }

        if (!$useCustomModel && $storedModel !== '' && !in_array($storedModel, $models, true)) {
            $useCustomModel = true;
            $storedCustomModel = $storedModel;
            $storedModel = '';
        }

        if ($useCustomModel) {
            $effectiveModel = $storedCustomModel !== '' ? $storedCustomModel : ($defaults['default_model'] ?? '');
            $modelOption = '__custom__';
        } else {
            $effectiveModel = $storedModel !== '' ? $storedModel : ($defaults['default_model'] ?? '');
            $modelOption = $effectiveModel;
        }

        $apiUrl = isset($saved['api_url']) && $saved['api_url'] !== ''
            ? trim((string) $saved['api_url'])
            : trim((string) ($legacy['api_url'] ?? ($defaults['default_api_url'] ?? '')));
        $apiUrl = self::normalizeApiUrl($provider, $apiUrl);

        if ($provider === 'deepseek') {
            $resolved = \Gcms\Ai\Drivers\DeepSeek::resolveModel(
                $effectiveModel,
                !empty($saved['thinking_enabled'])
            );
            $effectiveModel = $resolved['model'];
            if (in_array($effectiveModel, $models, true)) {
                $useCustomModel = false;
                $storedCustomModel = '';
                $modelOption = $effectiveModel;
            }
            if ($resolved['thinking']) {
                $saved['thinking_enabled'] = 1;
            }
        }

        $connection = [
            'api_key' => isset($saved['api_key']) ? (string) $saved['api_key'] : (string) ($legacy['api_key'] ?? ''),
            'api_url' => $apiUrl,
            'model' => $effectiveModel,
            'model_option' => $modelOption,
            'custom_model' => $useCustomModel ? $storedCustomModel : '',
            'use_custom_model' => $useCustomModel ? 1 : 0,
            'max_tokens' => isset($saved['max_tokens']) && $saved['max_tokens'] !== '' ? (int) $saved['max_tokens'] : (int) ($legacy['max_tokens'] ?? 1024),
            'temperature' => isset($saved['temperature']) && $saved['temperature'] !== '' ? (float) $saved['temperature'] : (float) ($legacy['temperature'] ?? 0.7)
        ];

        if ($provider === 'deepseek') {
            $thinkingEnabled = !empty($saved['thinking_enabled']);
            if (!$thinkingEnabled && ($storedCustomModel === 'deepseek-reasoner' || $storedModel === 'deepseek-reasoner')) {
                $thinkingEnabled = true;
            }
            $connection['thinking_enabled'] = $thinkingEnabled ? 1 : 0;
            $connection['reasoning_effort'] = \Gcms\Ai\Drivers\DeepSeek::normalizeEffort(
                $saved['reasoning_effort'] ?? ($defaults['default_reasoning_effort'] ?? 'high')
            );
        }

        return $connection;
    }

    /**
     * Create and return the configured AI driver.
     *
     * @param string|null $provider Provider name; null uses ai_provider from config
     * @param array       $config   Per-instance overrides (api_key, api_url, model, ...)
     *
     * @return \Gcms\Ai\Driver
     *
     * @throws \InvalidArgumentException for unknown provider names
     */
    public static function driver($provider = null, array $config = [])
    {
        if ($provider === null || $provider === '') {
            $stored = (new \Gcms\Ai\SettingsRepository())->connector();
            $provider = !empty($stored['ai_provider']) ? $stored['ai_provider'] : (!empty(self::$cfg->ai_provider) ? self::$cfg->ai_provider : 'openai');
        }
        $provider = strtolower(trim($provider));
        $providers = self::configuredProviders();

        if (!isset($providers[$provider])) {
            throw new \InvalidArgumentException('Unknown AI provider: '.$provider);
        }

        $connection = self::connectionSettings($provider);
        if (empty($config['api_key']) && !empty($connection['api_key'])) {
            $config['api_key'] = $connection['api_key'];
        }
        if (empty($config['api_url']) && !empty($connection['api_url'])) {
            $config['api_url'] = $connection['api_url'];
        }
        if (empty($config['model']) && !empty($connection['model'])) {
            $config['model'] = $connection['model'];
        }
        if (empty($config['max_tokens']) && !empty($connection['max_tokens'])) {
            $config['max_tokens'] = $connection['max_tokens'];
        }
        if (!isset($config['temperature']) && isset($connection['temperature'])) {
            $config['temperature'] = $connection['temperature'];
        }
        if ($provider === 'deepseek') {
            if (!isset($config['thinking_enabled']) && isset($connection['thinking_enabled'])) {
                $config['thinking_enabled'] = !empty($connection['thinking_enabled']);
            }
            if (empty($config['reasoning_effort']) && !empty($connection['reasoning_effort'])) {
                $config['reasoning_effort'] = $connection['reasoning_effort'];
            }
        }
        if (empty($config['provider'])) {
            $config['provider'] = $provider;
        }
        if (!empty($config['api_url'])) {
            $config['api_url'] = self::normalizeApiUrl($provider, $config['api_url']);
        } elseif (!empty($providers[$provider]['default_api_url'])) {
            $config['api_url'] = self::normalizeApiUrl($provider, $providers[$provider]['default_api_url']);
        }

        switch ($provider) {
        case 'gemini':
            return new \Gcms\Ai\Drivers\Gemini($config);

        case 'claude':
            return new \Gcms\Ai\Drivers\Claude($config);

        case 'deepseek':
            return new \Gcms\Ai\Drivers\DeepSeek($config);

        case 'openai':
        case 'groq':
        case 'openrouter':
        case 'vercel':
        case 'nvidia':
        case 'glm':
        case 'ollama':
        case 'lmstudio':
            return new \Gcms\Ai\Drivers\OpenAiCompatible($config);

        default:
            throw new \InvalidArgumentException('Unknown AI provider: '.$provider);
        }
    }

    /**
     * Normalize local-provider API URLs.
     *
     * Ollama exposes model lists at /api/tags but chat uses the OpenAI-compatible
     * endpoint at /v1/chat/completions. Users often paste the /api base by mistake.
     *
     * @param string $provider
     * @param string $apiUrl
     *
     * @return string
     */
    public static function normalizeApiUrl($provider, $apiUrl)
    {
        $provider = strtolower(trim((string) $provider));
        $apiUrl = rtrim(trim((string) $apiUrl), '/');
        if ($apiUrl === '' || !in_array($provider, ['ollama', 'lmstudio'], true)) {
            return $apiUrl;
        }
        if ($provider === 'ollama' && preg_match('#/api$#i', $apiUrl)) {
            return preg_replace('#/api$#i', '/v1', $apiUrl);
        }
        if (preg_match('#^https?://[^/]+$#i', $apiUrl)) {
            return $apiUrl.'/v1';
        }

        return $apiUrl;
    }
}
