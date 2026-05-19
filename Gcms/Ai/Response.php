<?php
/**
 * @filesource Gcms/Ai/Response.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Ai;

/**
 * Normalized AI response DTO
 *
 * Returned by every AI driver's chat() method so callers
 * never need to handle provider-specific response shapes.
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Response
{
    /**
     * Whether the request succeeded
     *
     * @var bool
     */
    public $success = false;

    /**
     * Text content returned by the model
     *
     * @var string
     */
    public $content = '';

    /**
     * Generated images returned by the provider.
     * Each item is a normalized array such as:
     *   ['url' => 'https://...', 'b64_json' => '...', 'mime_type' => 'image/png']
     *
     * @var array
     */
    public $images = [];

    /**
     * Model identifier that produced this response
     *
     * @var string
     */
    public $model = '';

    /**
     * Number of input (prompt) tokens consumed
     *
     * @var int
     */
    public $inputTokens = 0;

    /**
     * Number of output (completion) tokens generated
     *
     * @var int
     */
    public $outputTokens = 0;

    /**
     * Error message when success is false
     *
     * @var string
     */
    public $error = '';

    /**
     * Raw decoded API response for debugging
     *
     * @var array
     */
    public $raw = [];

    /**
     * Create a failed Response with an error message
     *
     * @param string $message Error description
     * @param array  $raw     Raw API response if available
     *
     * @return self
     */
    public static function fromError($message, array $raw = [])
    {
        $r = new self();
        $r->success = false;
        $r->error = $message;
        $r->raw = $raw;
        return $r;
    }
}
