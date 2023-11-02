<?php
/**
 * @filesource Kotchasan/Grid.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * This class represents a grid system used for layout purposes.
 *
 * @see https://www.kotchasan.com/
 */
class Grid extends \Kotchasan\Template
{
    /**
     * @var int The number of columns in the grid
     */
    protected $cols;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Set the default number of columns to 1
        $this->cols = 1;
    }

    /**
     * Get the number of columns in the grid.
     *
     * @return int The number of columns
     */
    public function getCols()
    {
        return $this->cols;
    }

    /**
     * Set the number of columns in the grid.
     *
     * @param int $cols The number of columns (must be greater than 0)
     *
     * @return static This Grid instance for method chaining
     */
    public function setCols($cols)
    {
        // Ensure the number of columns is at least 1
        $this->cols = max(1, (int) $cols);
        // Set the value of 'num' property to match the number of columns
        $this->num = $this->cols;
        return $this;
    }
}
