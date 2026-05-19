<?php
/**
 * @filesource Kotchasan/Exception/TemplateNotFoundException.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Kotchasan\Exception;

/**
 * Exception thrown when a template file is not found
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class TemplateNotFoundException extends \RuntimeException
{
    /**
     * The name of the template that was not found
     *
     * @var string
     */
    protected $templateName;

    /**
     * Array of paths that were attempted
     *
     * @var array
     */
    protected $attemptedPaths;

    /**
     * Class Constructor
     *
     * @param string $templateName   The name of the template
     * @param array  $attemptedPaths Array of paths that were attempted
     * @param int    $code           Exception code (default: 404)
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct($templateName, array $attemptedPaths = [], $code = 404,  ? \Throwable $previous = null)
    {
        $this->templateName = $templateName;
        $this->attemptedPaths = $attemptedPaths;

        $paths = empty($attemptedPaths) ? 'no paths specified' : implode(', ', $attemptedPaths);
        $message = "Template '{$templateName}' not found. Attempted paths: {$paths}";

        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the template name that was not found
     *
     * @return string
     */
    public function getTemplateName()
    {
        return $this->templateName;
    }

    /**
     * Get the array of paths that were attempted
     *
     * @return array
     */
    public function getAttemptedPaths()
    {
        return $this->attemptedPaths;
    }
}
