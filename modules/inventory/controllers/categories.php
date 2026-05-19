<?php
/**
 * @filesource modules/inventory/controllers/categories.php
 */

namespace Inventory\Categories;

class Controller extends \Index\Category\Controller
{
    /**
     * Supported category types.
     *
     * @var array
     */
    protected $categories = [
        'category_id' => '{LNG_Category}',
        'unit' => '{LNG_Unit}'
    ];
}