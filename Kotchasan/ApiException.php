<?php
/**
 * @filesource Kotchasan/ApiException.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * API Exception for handling API-specific errors
 */

namespace Kotchasan;

/**
 * API Exception class for handling API-specific errors.
 *
 * @see https://www.kotchasan.com/
 */
class ApiException extends \Exception
{
    /**
     * Constructor.
     *
     * @param string $message Exception message
     * @param int $code Exception code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(string $message = '', int $code = 0,  ? \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
