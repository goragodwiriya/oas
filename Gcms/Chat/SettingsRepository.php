<?php
/**
 * @filesource Gcms/Chat/SettingsRepository.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat;

use Kotchasan\Config;

/**
 * Config-backed settings for AI chat runtime and admin UI.
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
}
