<?php

namespace Kotchasan;

/**
 * Kotchasan Grid Class
 *
 * This class provides methods for managing a grid layout.
 * It allows setting and getting the number of columns in the grid.
 *
 * @package Kotchasan
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
