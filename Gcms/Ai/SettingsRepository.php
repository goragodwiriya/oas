<?php
/**
 * @filesource Gcms/Ai/SettingsRepository.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Ai;

use Kotchasan\Config;

/**
 * Config-backed settings for AI runtime and admin UI.
 *
 * All settings are stored in settings/config.php via the standard
 * Kotchasan Config save flow. No database table is required.
 *
 * @since 1.0
 */
class SettingsRepository extends \Kotchasan\KBase
{
    /**
     * Connector settings from config.
     *
     * @return array
     */
    public function connector(): array
    {
        return [
            'ai_enabled' => !empty(self::$cfg->ai_enabled) ? 1 : 0,
            'ai_provider' => !empty(self::$cfg->ai_provider) ? strtolower(trim((string) self::$cfg->ai_provider)) : 'openai',
            'ai_connections' => !empty(self::$cfg->ai_connections) && is_array(self::$cfg->ai_connections) ? self::$cfg->ai_connections : []
        ];
    }

    /**
     * Handoff workflow configuration from config.
     *
     * @return array
     */
    public function workflow(): array
    {
        return [
            'sla_minutes' => max(1, min(10080, (int) (self::$cfg->ai_chat_workflow_value ?? 60)))
        ];
    }

    /**
     * Persist workflow configuration to config file.
     *
     * @param array $workflow
     *
     * @return bool
     */
    public function saveWorkflow(array $workflow): bool
    {
        $config = Config::load(ROOT_PATH.'settings/config.php');
        $config->ai_chat_workflow_value = max(1, min(10080, (int) ($workflow['sla_minutes'] ?? 60)));

        return Config::save($config, ROOT_PATH.'settings/config.php');
    }

    public function messages(): array
    {
        return [];
    }

    /**
     * @param array $messages
     */
    public function saveMessages(array $messages): bool
    {
        return false;
    }
}
