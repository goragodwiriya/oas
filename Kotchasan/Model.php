<?php
/**
 * @filesource Kotchasan/Model.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

use Kotchasan\Database\Query;

/**
 * This class serves as the base class for all models in the application.
 * It extends the Query class and provides common functionality for interacting with the database.
 *
 * @see https://www.kotchasan.com/
 */
class Model extends Query
{
    /**
     * The name of the database connection to be used.
     * This is used to load the connection settings from settings/database.php.
     *
     * @var string
     */
    protected $conn = 'mysql';

    /**
     * Class constructor
     */
    public function __construct()
    {
        parent::__construct($this->conn);
    }

    /**
     * Create a new instance of the model.
     *
     * @return static
     */
    public static function create()
    {
        return new static;
    }

    /**
     * Create a new database connection instance.
     *
     * @return \Kotchasan\Database\Driver
     */
    public static function createDb()
    {
        $model = new static;
        return $model->db();
    }

    /**
     * Create a new QueryBuilder instance.
     *
     * @return \Kotchasan\Database\QueryBuilder
     */
    public static function createQuery()
    {
        $model = new static;
        return $model->db()->createQuery();
    }
}
