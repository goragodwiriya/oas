<?php
/**
 * @filesource modules/index/controllers/menus.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Index\Menus;

use Gcms\Api as ApiController;

/**
 * API Authentication Controller
 *
 * Handles user authentication endpoints with production-grade security
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * Get menu data
     *
     * @param Object $login
     *
     * @return array
     */
    public static function getMenus($login)
    {
        // Menu data - 2-level nested structure
        $menus = [
            'dashboard' => [
                'title' => 'Dashboard',
                'url' => '/',
                'icon' => 'icon-dashboard'
            ]
        ];

        if (!$login) {
            return array_values($menus);
        }

        // Add admin menus if user is admin
        if (ApiController::hasPermission($login, 'can_config')) {
            $submenus = [
                [
                    'title' => 'General Settings',
                    'url' => '/general-settings',
                    'icon' => 'icon-cog'
                ],
                [
                    'title' => 'Company Settings',
                    'url' => '/company-settings',
                    'icon' => 'icon-office'
                ],
                [
                    'title' => 'Theme Settings',
                    'url' => '/theme-settings',
                    'icon' => 'icon-brush'
                ],
                [
                    'title' => 'Email Settings',
                    'url' => '/email-settings',
                    'icon' => 'icon-email'
                ],
                [
                    'title' => 'Manage languages',
                    'url' => '/languages',
                    'icon' => 'icon-language'
                ]
            ];

            $adminMenu = ApiController::isAdmin($login);
            $adminMenu &= ApiController::isNotDemoMode($login);
            if ($adminMenu || ApiController::isSuperAdmin($login)) {
                $menus['users'] = [
                    'title' => 'Users',
                    'url' => '/users',
                    'icon' => 'icon-users'
                ];
                $submenus[] = [
                    'title' => 'Department',
                    'url' => '/categories?type=department',
                    'icon' => 'icon-tags'
                ];
                $submenus[] = [
                    'title' => 'Member status',
                    'url' => '/user-status',
                    'icon' => 'icon-star0'
                ];
                $submenus[] = [
                    'title' => 'Permissions',
                    'url' => '/permission',
                    'icon' => 'icon-list'
                ];
                $submenus[] = [
                    'title' => 'API Settings',
                    'url' => '/api-settings',
                    'icon' => 'icon-host'
                ];
                $submenus[] = [
                    'title' => 'LINE Settings',
                    'url' => '/line-settings',
                    'icon' => 'icon-line'
                ];
                $submenus[] = [
                    'title' => 'Telegram Settings',
                    'url' => '/telegram-settings',
                    'icon' => 'icon-telegram'
                ];
                $submenus[] = [
                    'title' => 'SMS Settings',
                    'url' => '/sms-settings',
                    'icon' => 'icon-mobile'
                ];
                $submenus[] = [
                    'title' => 'AI Settings',
                    'url' => '/ai-settings',
                    'icon' => 'icon-support'
                ];
            }
            $submenus[] = [
                'title' => 'Usage history',
                'url' => '/usage',
                'icon' => 'icon-report'
            ];
            $menus['settings'] = [
                'title' => 'Settings',
                'icon' => 'icon-settings',
                'children' => $submenus
            ];
        }

        // Load module menus
        $menus = self::initModule($menus, 'initMenus', $login);

        // return menus
        return array_values($menus);
    }
}
