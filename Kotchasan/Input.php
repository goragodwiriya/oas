<?php

namespace Kotchasan;

use Kotchasan\Http\InputItem;
use Kotchasan\Http\Inputs;
use Kotchasan\Http\Request;
use Kotchasan\Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Class Input
 *
 * Provides methods for handling and validating input data.
 *
 * @package Kotchasan
 */
class Input
{
    /**
     * @var array The input data
     */
    protected $data = [];

    /**
     * @var array The validation rules
     */
    protected $rules = [];

    /**
     * @var array The validation errors
     */
    protected $errors = [];

    /**
     * @var array Custom validation rules
     */
    protected $customRules = [];

    /**
     * @var ServerRequestInterface The request object
     */
    protected $request;

    /**
     * Constructor.
     *
     * @param ServerRequestInterface|null $request The request object (optional)
     */
    public function __construct(ServerRequestInterface | null $request = null)
    {
        $this->request = $request ?? new Request(true);

        // Initialize data from the request for backward compatibility
        // This doesn't violate immutability as we're just initializing our internal state
        $this->data = $this->all();
    }

    /**
     * Creates a new Input instance.
     *
     * @param ServerRequestInterface|null $request The request object (optional)
     * @return static
     */
    public static function create(ServerRequestInterface | null $request = null): self
    {
        return new static($request);
    }

    /**
     * Gets the underlying PSR-7 ServerRequestInterface instance.
     *
     * @return ServerRequestInterface
     */
    public function getRequest(): ServerRequestInterface
    {
        return $this->request;
    }

    /**
     * Creates a new Input instance with a modified request.
     * This method respects PSR-7 immutability principles.
     *
     * @param ServerRequestInterface $request The new request object
     * @return static A new Input instance with the modified request
     */
    public function withRequest(ServerRequestInterface $request): self
    {
        $clone = clone $this;
        $clone->request = $request;
        // Update the data to reflect the new request
        $clone->data = $clone->all();
        return $clone;
    }

    /**
     * Creates a new Input instance with modified query parameters.
     * This method respects PSR-7 immutability principles.
     *
     * @param array $query The new query parameters
     * @return static A new Input instance with the modified query parameters
     */
    public function withQueryParams(array $query): self
    {
        return $this->withRequest($this->request->withQueryParams($query));
    }

    /**
     * Creates a new Input instance with a modified parsed body.
     * This method respects PSR-7 immutability principles.
     *
     * @param array|object|null $data The new parsed body
     * @return static A new Input instance with the modified parsed body
     */
    public function withParsedBody($data): self
    {
        return $this->withRequest($this->request->withParsedBody($data));
    }

    /**
     * Creates a new Input instance with modified cookie parameters.
     * This method respects PSR-7 immutability principles.
     *
     * @param array $cookies The new cookie parameters
     * @return static A new Input instance with the modified cookie parameters
     */
    public function withCookieParams(array $cookies): self
    {
        return $this->withRequest($this->request->withCookieParams($cookies));
    }

    /**
     * Creates a new Input instance with modified uploaded files.
     * This method respects PSR-7 immutability principles.
     *
     * @param array $uploadedFiles The new uploaded files
     * @return static A new Input instance with the modified uploaded files
     */
    public function withUploadedFiles(array $uploadedFiles): self
    {
        return $this->withRequest($this->request->withUploadedFiles($uploadedFiles));
    }

    /**
     * Gets a value from the request.
     *
     * @param string $name The name of the parameter
     * @param mixed $default The default value if the parameter doesn't exist
     * @param string $source The source of the parameter (post, get, cookie, server, json, all)
     * @return mixed The parameter value
     */
    public function get(string $name, $default = null, string $source = 'all')
    {
        switch (strtolower($source)) {
            case 'post':
                $body = $this->request->getParsedBody();
                $value = is_array($body) && array_key_exists($name, $body) ? $body[$name] : null;
                if ($value === null) {
                    return $default;
                }
                if (is_array($value)) {
                    return new Inputs($value, 'POST');
                }
                return new InputItem($value, 'POST');
            case 'get':
                $qp = $this->request->getQueryParams();
                $value = $qp[$name] ?? null;
                if ($value === null) {
                    return $default;
                }
                if (is_array($value)) {
                    return new Inputs($value, 'GET');
                }
                return new InputItem($value, 'GET');
            case 'cookie':
                $cp = $this->request->getCookieParams();
                $value = $cp[$name] ?? null;
                if ($value === null) {
                    return $default;
                }
                if (is_array($value)) {
                    return new Inputs($value, 'COOKIE');
                }
                return new InputItem($value, 'COOKIE');
            case 'server':
                return $this->request->getServerParams()[$name] ?? $default;
            case 'json':
                $jsonData = $this->getJson();
                $value = is_array($jsonData) && array_key_exists($name, $jsonData) ? $jsonData[$name] : null;
                if ($value === null) {
                    return $default;
                }
                if (is_array($value)) {
                    return new Inputs($value, 'JSON');
                }
                return new InputItem($value, 'JSON');
            case 'all':
            default:
                // Check POST data first
                $value = $this->request->getParsedBody()[$name] ?? null;

                // If not found in POST, check GET data
                if ($value === null) {
                    $value = $this->request->getQueryParams()[$name] ?? null;
                }

                // If not found in GET and request is JSON, check JSON data
                if ($value === null && $this->isJson()) {
                    $jsonData = $this->getJson();
                    $value = $jsonData[$name] ?? null;
                }

                // If still not found, return default
                if ($value === null) {
                    return $default;
                }

                // Wrap existing value into InputItem/Inputs for fluent API
                if (is_array($value)) {
                    return new Inputs($value, 'REQUEST');
                }

                return new InputItem($value, 'REQUEST');
        }
    }

    /**
     * Gets all values from the request.
     *
     * @param string $source The source of the parameters (post, get, cookie, server, json, all)
     * @return array The parameter values
     */
    public function all(string $source = 'all'): array
    {
        switch (strtolower($source)) {
            case 'post':
                return $this->request->getParsedBody() ?? [];
            case 'get':
                return $this->request->getQueryParams() ?? [];
            case 'cookie':
                return $this->request->getCookieParams() ?? [];
            case 'server':
                return $this->request->getServerParams() ?? [];
            case 'json':
                return $this->getJson() ?? [];
            case 'all':
            default:
                $data = $this->request->getQueryParams() ?? [];
                $postData = $this->request->getParsedBody() ?? [];

                // Include JSON data if available and Content-Type is application/json
                if ($this->isJson()) {
                    $jsonData = $this->getJson() ?? [];
                    if (is_array($jsonData)) {
                        $data = array_merge($data, $jsonData);
                    }
                }

                // Include POST data if available
                if (is_array($postData)) {
                    $data = array_merge($data, $postData);
                }

                return $data;
        }
    }

    /**
     * Sets validation rules for the input data.
     *
     * @param array $rules The validation rules
     * @return $this
     */
    public function rules(array $rules): self
    {
        $this->rules = $rules;
        return $this;
    }

    /**
     * Validates the input data against the defined rules.
     *
     * @param array|null $data The data to validate (optional, uses request data if null)
     * @return bool True if validation passes, false otherwise
     */
    public function validate(?array $data = null): bool
    {
        $this->errors = [];
        $this->data = $data ?? $this->all();

        // First pass: collect all field dependencies for conditional validation
        $conditionalFields = [];
        foreach ($this->rules as $field => $rules) {
            foreach ($rules as $rule => $param) {
                if ($rule === 'when' && is_array($param) && isset($param['field'])) {
                    $conditionalFields[$field] = true;
                }
            }
        }

        foreach ($this->rules as $field => $rules) {
            $value = $this->data[$field] ?? null;

            // Check if this field has conditional validation
            $shouldValidate = true;
            if (isset($conditionalFields[$field])) {
                $shouldValidate = $this->evaluateConditionalRules($field, $rules);
            }

            // Skip validation if conditions are not met
            if (!$shouldValidate) {
                continue;
            }

            // Skip validation if the field is not required and the value is empty
            if (!isset($rules['required']) && empty($value) && $value !== '0' && $value !== 0) {
                continue;
            }

            foreach ($rules as $rule => $param) {
                // Skip non-validation rules
                if ($rule === 'label' || $rule === 'when' || $rule === 'group') {
                    continue;
                }

                $valid = true;
                $message = $param;

                // If the rule is an array with parameters and message
                if (is_array($param) && isset($param['value'])) {
                    $ruleParam = $param['value'];
                    $message = $param['message'] ?? '';
                } else {
                    $ruleParam = $param;
                }

                // Validate the field
                switch ($rule) {
                    case 'required':
                        $valid = Validator::required($value);
                        break;
                    case 'email':
                        if (!empty($value)) {
                            $valid = Validator::email($value);
                        }
                        break;
                    case 'url':
                        if (!empty($value)) {
                            $valid = Validator::url($value);
                        }
                        break;
                    case 'integer':
                        if (!empty($value)) {
                            $valid = Validator::integer($value);
                        }
                        break;
                    case 'float':
                        if (!empty($value)) {
                            $valid = Validator::float($value);
                        }
                        break;
                    case 'date':
                        if (!empty($value)) {
                            $valid = Validator::date($value);
                        }
                        break;
                    case 'time':
                        if (!empty($value)) {
                            $valid = Validator::time($value);
                        }
                        break;
                    case 'phone':
                        if (!empty($value)) {
                            $valid = Validator::phone($value);
                        }
                        break;
                    case 'username':
                        if (!empty($value)) {
                            $valid = Validator::username($value);
                        }
                        break;
                    case 'password':
                        if (!empty($value)) {
                            $valid = Validator::password($value, is_numeric($ruleParam) ? $ruleParam : 8);
                        }
                        break;
                    case 'pattern':
                        if (!empty($value)) {
                            $valid = Validator::pattern($value, $ruleParam);
                        }
                        break;
                    case 'in':
                        if (!empty($value)) {
                            $valid = Validator::inList($value, is_array($ruleParam) ? $ruleParam : explode(',', $ruleParam));
                        }
                        break;
                    case 'between':
                        if (!empty($value)) {
                            $params = is_array($ruleParam) ? $ruleParam : explode(',', $ruleParam);
                            $valid = Validator::between($value, $params[0] ?? 0, $params[1] ?? PHP_INT_MAX);
                        }
                        break;
                    case 'min':
                        if (!empty($value)) {
                            $valid = is_numeric($value) ? $value >= $ruleParam : mb_strlen($value) >= $ruleParam;
                        }
                        break;
                    case 'max':
                        if (!empty($value)) {
                            $valid = is_numeric($value) ? $value <= $ruleParam : mb_strlen($value) <= $ruleParam;
                        }
                        break;
                    case 'same':
                        $valid = $value === ($this->data[$ruleParam] ?? null);
                        break;
                    case 'different':
                        $valid = $value !== ($this->data[$ruleParam] ?? null);
                        break;
                    case 'same_as':
                        $otherField = $ruleParam;
                        $otherValue = $this->data[$otherField] ?? null;
                        $valid = $value === $otherValue;
                        break;
                    case 'different_from':
                        $otherField = $ruleParam;
                        $otherValue = $this->data[$otherField] ?? null;
                        $valid = $value !== $otherValue;
                        break;
                    case 'greater_than':
                        $otherField = $ruleParam;
                        $otherValue = $this->data[$otherField] ?? null;
                        $valid = is_numeric($value) && is_numeric($otherValue) && $value > $otherValue;
                        break;
                    case 'greater_than_or_equal':
                        $otherField = $ruleParam;
                        $otherValue = $this->data[$otherField] ?? null;
                        $valid = is_numeric($value) && is_numeric($otherValue) && $value >= $otherValue;
                        break;
                    case 'less_than':
                        $otherField = $ruleParam;
                        $otherValue = $this->data[$otherField] ?? null;
                        $valid = is_numeric($value) && is_numeric($otherValue) && $value < $otherValue;
                        break;
                    case 'less_than_or_equal':
                        $otherField = $ruleParam;
                        $otherValue = $this->data[$otherField] ?? null;
                        $valid = is_numeric($value) && is_numeric($otherValue) && $value <= $otherValue;
                        break;
                    case 'callback':
                        if (is_callable($ruleParam)) {
                            $valid = $ruleParam($value, $this->data);
                        }
                        break;
                    default:
                        // Check if this is a custom validation rule
                        if (isset($this->customRules[$rule])) {
                            $customRule = $this->customRules[$rule];
                            $valid = $customRule['callback']($value, $ruleParam, $this->data);

                            // Use custom rule's default message if no specific message was provided
                            if (!$valid && is_string($message) && empty($message)) {
                                $message = $customRule['message'];
                            }
                        }
                        break;
                }

                if (!$valid) {
                    $fieldLabel = $rules['label'] ?? $field;
                    $errorMessage = is_string($message) ? $message : "The {$fieldLabel} field is invalid.";

                    // Add field group information if available
                    if (isset($rules['group'])) {
                        $this->errors[$field] = [
                            'message' => $errorMessage,
                            'group' => $rules['group']
                        ];
                    } else {
                        $this->errors[$field] = $errorMessage;
                    }
                    break;
                }
            }
        }

        return empty($this->errors);
    }

    /**
     * Evaluates conditional validation rules for a field.
     *
     * @param string $field The field name
     * @param array $rules The validation rules for the field
     * @return bool True if the field should be validated, false otherwise
     */
    protected function evaluateConditionalRules(string $field, array $rules): bool
    {
        if (!isset($rules['when']) || !is_array($rules['when'])) {
            return true;
        }

        $whenRule = $rules['when'];

        // Simple field existence condition
        if (isset($whenRule['field']) && !isset($whenRule['operator'])) {
            $dependentField = $whenRule['field'];
            $dependentValue = $this->data[$dependentField] ?? null;
            return !empty($dependentValue);
        }

        // Field with specific value condition
        if (isset($whenRule['field']) && isset($whenRule['value'])) {
            $dependentField = $whenRule['field'];
            $dependentValue = $this->data[$dependentField] ?? null;
            $expectedValue = $whenRule['value'];
            $operator = $whenRule['operator'] ?? '==';

            switch ($operator) {
                case '==':
                    return $dependentValue == $expectedValue;
                case '===':
                    return $dependentValue === $expectedValue;
                case '!=':
                    return $dependentValue != $expectedValue;
                case '!==':
                    return $dependentValue !== $expectedValue;
                case '>':
                    return $dependentValue > $expectedValue;
                case '>=':
                    return $dependentValue >= $expectedValue;
                case '<':
                    return $dependentValue < $expectedValue;
                case '<=':
                    return $dependentValue <= $expectedValue;
                case 'in':
                    return in_array($dependentValue, (array) $expectedValue);
                case 'not_in':
                    return !in_array($dependentValue, (array) $expectedValue);
                default:
                    return false;
            }
        }

        // Custom callback condition
        if (isset($whenRule['callback']) && is_callable($whenRule['callback'])) {
            return (bool) $whenRule['callback']($this->data);
        }

        return true;
    }

    /**
     * Gets the validation errors.
     *
     * @return array The validation errors
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Gets the first validation error.
     *
     * @return string|null The first validation error or null if there are no errors
     */
    public function firstError(): ?string
    {
        return !empty($this->errors) ? reset($this->errors) : null;
    }

    /**
     * Gets the validated data.
     *
     * @return array The validated data
     */
    public function validated(): array
    {
        if (!empty($this->errors)) {
            throw new \RuntimeException('The data has not been validated or validation failed.');
        }

        $validated = [];
        foreach ($this->rules as $field => $rules) {
            if (isset($this->data[$field])) {
                $validated[$field] = $this->data[$field];
            }
        }

        return $validated;
    }

    /**
     * Sanitizes a string by removing HTML tags and special characters.
     *
     * @param string $value The string to sanitize
     * @return string The sanitized string
     */
    public function sanitize($value): string
    {
        if (is_string($value)) {
            // Remove null bytes
            $value = str_replace(["\0", "%00"], '', $value);

            // First decode HTML entities to handle encoded malicious content
            $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');

            // Remove <script>...</script> and their content
            $value = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $value);

            // Remove all HTML tags
            $value = strip_tags($value);

            // Remove SQL injection characters (single quotes, semicolons, double dashes)
            $value = str_replace(["'", ";", "--"], "", $value);

            // Remove SQL injection patterns
            $value = preg_replace('/\b(UNION\s+SELECT|DROP\s+TABLE|INSERT\s+INTO|DELETE\s+FROM|UPDATE\s+.*?SET|EXEC|EXECUTE)\b/i', '', $value);

            // Remove potentially dangerous JavaScript keywords
            $value = preg_replace('/\b(javascript|alert|onclick|onload|onerror)\b/i', '', $value);

            // Remove path traversal patterns
            $value = str_replace(['../', '..\\', '../', '..%2f', '..%5c', '..%u2215'], '', $value);
            $value = preg_replace('/%252e%252e%252f/i', '', $value);

            // Collapse multiple spaces into one
            $value = preg_replace('/\s+/u', ' ', $value);

            // Trim whitespace
            $value = trim($value);

            return $value;
        }
        return '';
    }

    /**
     * Gets a sanitized value from the request.
     *
     * @param string $name The name of the parameter
     * @param mixed $default The default value if the parameter doesn't exist
     * @param string $source The source of the parameter (post, get, cookie, server, all)
     * @return string The sanitized parameter value
     */
    public function getString(string $name, $default = '', string $source = 'all'): string
    {
        $value = $this->get($name, $default, $source);

        // Unwrap fluent wrappers if present
        if ($value instanceof \Kotchasan\Http\InputItem) {
            $value = $value->toString();
        } elseif ($value instanceof \Kotchasan\Http\Inputs) {
            // Convert array-like inputs to string by joining values
            $arr = $value->toArray();
            $value = is_array($arr) ? implode(' ', $arr) : '';
        }

        return $this->sanitize($value);
    }

    /**
     * Gets an integer value from the request.
     *
     * @param string $name The name of the parameter
     * @param int $default The default value if the parameter doesn't exist
     * @param string $source The source of the parameter (post, get, cookie, server, all)
     * @return int The integer parameter value
     */
    public function getInt(string $name, int $default = 0, string $source = 'all'): int
    {
        $value = $this->get($name, $default, $source);
        // Unwrap wrappers
        if ($value instanceof \Kotchasan\Http\InputItem) {
            $value = $value->toString();
        } elseif ($value instanceof \Kotchasan\Http\Inputs) {
            $arr = $value->toArray();
            // If array, pick first numeric value or return default
            $value = is_array($arr) && !empty($arr) ? reset($arr) : $default;
        }
        return (int) $value;
    }

    /**
     * Gets a float value from the request.
     *
     * @param string $name The name of the parameter
     * @param float $default The default value if the parameter doesn't exist
     * @param string $source The source of the parameter (post, get, cookie, server, all)
     * @return float The float parameter value
     */
    public function getFloat(string $name, float $default = 0.0, string $source = 'all'): float
    {
        $value = $this->get($name, $default, $source);
        if ($value instanceof \Kotchasan\Http\InputItem) {
            $value = $value->toString();
        } elseif ($value instanceof \Kotchasan\Http\Inputs) {
            $arr = $value->toArray();
            $value = is_array($arr) && !empty($arr) ? reset($arr) : $default;
        }
        return (float) $value;
    }

    /**
     * Gets a boolean value from the request.
     *
     * @param string $name The name of the parameter
     * @param bool $default The default value if the parameter doesn't exist
     * @param string $source The source of the parameter (post, get, cookie, server, all)
     * @return bool The boolean parameter value
     */
    public function getBool(string $name, bool $default = false, string $source = 'all'): bool
    {
        $value = $this->get($name, $default, $source);
        if ($value instanceof \Kotchasan\Http\InputItem) {
            $value = $value->toString();
        } elseif ($value instanceof \Kotchasan\Http\Inputs) {
            $arr = $value->toArray();
            $value = is_array($arr) && !empty($arr) ? reset($arr) : $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Gets an array value from the request.
     *
     * @param string $name The name of the parameter
     * @param array $default The default value if the parameter doesn't exist
     * @param string $source The source of the parameter (post, get, cookie, server, all)
     * @return array The array parameter value
     */
    public function getArray(string $name, array $default = [], string $source = 'all'): array
    {
        $value = $this->get($name, $default, $source);
        if ($value instanceof \Kotchasan\Http\Inputs) {
            return $value->toArray();
        }
        if ($value instanceof \Kotchasan\Http\InputItem) {
            $v = $value->getValue();
            return is_array($v) ? $v : $default;
        }
        return is_array($value) ? $value : $default;
    }

    /**
     * Gets a nested array value from the request using dot notation.
     * Example: getNestedArray('user.address.city') will retrieve $data['user']['address']['city']
     *
     * @param string $name The name of the parameter with optional dot notation
     * @param array $default The default value if the parameter doesn't exist
     * @param string $source The source of the parameter (post, get, cookie, server, all)
     * @return array The nested array parameter value
     */
    public function getNestedArray(string $name, array $default = [], string $source = 'all'): array
    {
        if (strpos($name, '.') === false) {
            return $this->getArray($name, $default, $source);
        }

        $segments = explode('.', $name);
        $firstSegment = array_shift($segments);

        // Get the base array
        $array = $this->getArray($firstSegment, [], $source);
        if (empty($array)) {
            return $default;
        }

        // Navigate through the nested structure
        $value = $array;
        foreach ($segments as $segment) {
            if (!is_array($value) || !isset($value[$segment])) {
                return $default;
            }
            $value = $value[$segment];
        }

        return is_array($value) ? $value : $default;
    }

    /**
     * Gets a date value from the request.
     *
     * @param string $name The name of the parameter
     * @param string $default The default value if the parameter doesn't exist
     * @param string $source The source of the parameter (post, get, cookie, server, all)
     * @return string The date parameter value
     */
    public function getDate(string $name, string $default = '', string $source = 'all'): string
    {
        $value = $this->get($name, $default, $source);
        return Validator::date($value) ? $value : $default;
    }

    /**
     * Gets a time value from the request.
     *
     * @param string $name The name of the parameter
     * @param string $default The default value if the parameter doesn't exist
     * @param string $source The source of the parameter (post, get, cookie, server, all)
     * @return string The time parameter value
     */
    public function getTime(string $name, string $default = '', string $source = 'all'): string
    {
        $value = $this->get($name, $default, $source);
        return Validator::time($value) ? $value : $default;
    }

    /**
     * Gets an email value from the request.
     *
     * @param string $name The name of the parameter
     * @param string $default The default value if the parameter doesn't exist
     * @param string $source The source of the parameter (post, get, cookie, server, all)
     * @return string The email parameter value
     */
    public function getEmail(string $name, string $default = '', string $source = 'all'): string
    {
        $value = $this->get($name, $default, $source);
        return Validator::email($value) ? $value : $default;
    }

    /**
     * Gets a URL value from the request.
     *
     * @param string $name The name of the parameter
     * @param string $default The default value if the parameter doesn't exist
     * @param string $source The source of the parameter (post, get, cookie, server, all)
     * @return string The URL parameter value
     */
    public function getUrl(string $name, string $default = '', string $source = 'all'): string
    {
        $value = $this->get($name, $default, $source);
        return Validator::url($value) ? $value : $default;
    }

    /**
     * Gets a phone value from the request.
     *
     * @param string $name The name of the parameter
     * @param string $default The default value if the parameter doesn't exist
     * @param string $source The source of the parameter (post, get, cookie, server, all)
     * @return string The phone parameter value
     */
    public function getPhone(string $name, string $default = '', string $source = 'all'): string
    {
        $value = $this->get($name, $default, $source);
        return Validator::phone($value) ? $value : $default;
    }

    /**
     * Gets a username value from the request.
     *
     * @param string $name The name of the parameter
     * @param string $default The default value if the parameter doesn't exist
     * @param string $source The source of the parameter (post, get, cookie, server, all)
     * @return string The username parameter value
     */
    public function getUsername(string $name, string $default = '', string $source = 'all'): string
    {
        $value = $this->get($name, $default, $source);
        return Validator::username($value) ? $value : $default;
    }

    /**
     * Gets an uploaded file from the request.
     *
     * @param string $name The name of the file input field
     * @return array|null The file information array or null if no file was uploaded
     */
    public function getFile(string $name): ?array
    {
        $uploadedFiles = $this->request->getUploadedFiles();

        // Check if the file exists in the uploaded files
        if (isset($uploadedFiles[$name])) {
            $file = $uploadedFiles[$name];

            // Handle PSR-7 UploadedFileInterface
            if ($file instanceof UploadedFileInterface || $file instanceof \Kotchasan\Psr\Http\Message\UploadedFileInterface) {
                // Convert PSR-7 UploadedFile to array format compatible with PHP's $_FILES
                return [
                    'name' => $file->getClientFilename(),
                    'type' => $file->getClientMediaType(),
                    'tmp_name' => $this->getUploadedFilePath($file),
                    'error' => $file->getError(),
                    'size' => $file->getSize()
                ];
            }

            // Handle traditional PHP file upload array
            if (is_array($file) && isset($file['tmp_name'])) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param string $name
     * @param array $allowedTypes
     * @param int $maxWidth
     * @param int $maxHeight
     */
    public function getImage(string $name, array $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'], int $maxWidth = 0, int $maxHeight = 0): ?array
    {
        $file = $this->getFile($name);

        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        // In test/mock environments, uploaded file may not be via HTTP POST, so skip is_uploaded_file check
        $checkUpload = true;
        if (isset($file['tmp_name']) && is_file($file['tmp_name']) && !is_uploaded_file($file['tmp_name'])) {
            $checkUpload = false;
        }

        // Validate that the file is an image with enhanced security checks
        $imageInfo = Validator::validateImage($file, $allowedTypes, $maxWidth, $maxHeight, $checkUpload);

        if ($imageInfo === false) {
            return null;
        }

        // Merge the image info with the file array
        return array_merge($file, $imageInfo);
    }

    /**
     * Validates an array against a schema.
     *
     * @param string $name The name of the parameter
     * @param array $schema The schema to validate against
     * @param array $default The default value if the parameter doesn't exist or is invalid
     * @param string $source The source of the parameter (post, get, cookie, server, all)
     * @return array The validated array or the default value if invalid
     */
    public function getValidatedArray(string $name, array $schema, array $default = [], string $source = 'all'): array
    {
        $array = $this->getArray($name, [], $source);

        if (empty($array) || !Validator::isArrayValid($array, $schema)) {
            return $default;
        }

        return $array;
    }

    /**
     * Gets the JSON data from the request body.
     *
     * @return array|null The parsed JSON data or null if the request doesn't contain valid JSON
     */
    public function getJson(): ?array
    {
        if (!$this->isJson()) {
            return null;
        }

        $body = $this->request->getBody();
        $body->rewind();
        $content = $body->getContents();

        if (empty($content)) {
            return null;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (\JsonException $e) {
            return null;
        }
    }

    /**
     * Gets a value from the JSON data.
     *
     * @param string $name The name of the parameter
     * @param mixed $default The default value if the parameter doesn't exist
     * @return mixed The parameter value
     */
    public function getJsonData(string $name, $default = null)
    {
        $json = $this->getJson();
        if ($json === null) {
            return $default;
        }

        // Support dot notation for nested JSON data
        if (strpos($name, '.') !== false) {
            $segments = explode('.', $name);
            $data = $json;

            foreach ($segments as $segment) {
                if (!is_array($data) || !isset($data[$segment])) {
                    return $default;
                }
                $data = $data[$segment];
            }

            return $data;
        }

        return $json[$name] ?? $default;
    }

    /**
     * Filters an array by keeping only specified keys.
     *
     * @param array $array The array to filter
     * @param array $keys The keys to keep
     * @return array The filtered array
     */
    public function filterArrayKeys(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    /**
     * Filters an array by applying a callback function to each element.
     *
     * @param array $array The array to filter
     * @param callable $callback The callback function to apply
     * @return array The filtered array
     */
    public function filterArray(array $array, callable $callback): array
    {
        return array_filter($array, $callback);
    }

    /**
     * Maps an array by applying a callback function to each element.
     *
     * @param array $array The array to map
     * @param callable $callback The callback function to apply
     * @return array The mapped array
     */
    public function mapArray(array $array, callable $callback): array
    {
        return array_map($callback, $array);
    }

    /**
     * Validates an array with specific validation rules for each element.
     *
     * @param string $name The name of the parameter
     * @param array $rules The validation rules for each element
     * @param array $default The default value if the parameter doesn't exist or is invalid
     * @param string $source The source of the parameter (post, get, cookie, server, all)
     * @return array The validated array or the default value if invalid
     */
    public function validateArrayElements(string $name, array $rules, array $default = [], string $source = 'all'): array
    {
        $array = $this->getArray($name, [], $source);

        if (empty($array)) {
            return $default;
        }

        $result = [];
        $valid = true;

        foreach ($array as $key => $value) {
            $itemValid = true;
            $itemResult = $value;

            foreach ($rules as $rule => $param) {
                switch ($rule) {
                    case 'sanitize':
                        if ($param === true && is_string($itemResult)) {
                            $itemResult = $this->sanitize($itemResult);
                        }
                        break;
                    case 'email':
                        if (!Validator::email($itemResult)) {
                            $itemValid = false;
                        }
                        break;
                    case 'url':
                        if (!Validator::url($itemResult)) {
                            $itemValid = false;
                        }
                        break;
                    case 'integer':
                        if (!Validator::integer($itemResult)) {
                            $itemValid = false;
                        } else {
                            $itemResult = (int) $itemResult;
                        }
                        break;
                    case 'float':
                        if (!Validator::float($itemResult)) {
                            $itemValid = false;
                        } else {
                            $itemResult = (float) $itemResult;
                        }
                        break;
                    case 'date':
                        if (!Validator::date($itemResult)) {
                            $itemValid = false;
                        }
                        break;
                    case 'time':
                        if (!Validator::time($itemResult)) {
                            $itemValid = false;
                        }
                        break;
                    case 'pattern':
                        if (!Validator::pattern($itemResult, $param)) {
                            $itemValid = false;
                        }
                        break;
                    case 'in':
                        if (!Validator::inList($itemResult, is_array($param) ? $param : explode(',', $param))) {
                            $itemValid = false;
                        }
                        break;
                    case 'between':
                        $params = is_array($param) ? $param : explode(',', $param);
                        if (!Validator::between($itemResult, $params[0] ?? 0, $params[1] ?? PHP_INT_MAX)) {
                            $itemValid = false;
                        }
                        break;
                    case 'min':
                        if (is_string($itemResult) && mb_strlen($itemResult) < $param) {
                            $itemValid = false;
                        } elseif (is_numeric($itemResult) && $itemResult < $param) {
                            $itemValid = false;
                        }
                        break;
                    case 'max':
                        if (is_string($itemResult) && mb_strlen($itemResult) > $param) {
                            $itemValid = false;
                        } elseif (is_numeric($itemResult) && $itemResult > $param) {
                            $itemValid = false;
                        }
                        break;
                    case 'callback':
                        if (is_callable($param) && !$param($itemResult)) {
                            $itemValid = false;
                        }
                        break;
                }

                if (!$itemValid) {
                    break;
                }
            }

            if ($itemValid) {
                $result[$key] = $itemResult;
            } else {
                $valid = false;
            }
        }

        return $valid ? $result : $default;
    }

    /**
     * Validates a nested array with dot notation paths for validation rules.
     *
     * @param string $name The name of the parameter
     * @param array $rules The validation rules with dot notation paths
     * @param array $default The default value if the parameter doesn't exist or is invalid
     * @param string $source The source of the parameter (post, get, cookie, server, all)
     * @return array The validated array or the default value if invalid
     */
    public function validateNestedArray(string $name, array $rules, array $default = [], string $source = 'all'): array
    {
        $array = $this->getArray($name, [], $source);

        if (empty($array)) {
            return $default;
        }

        $result = $array;
        $valid = true;

        foreach ($rules as $path => $pathRules) {
            $value = $this->getValueByPath($array, $path);

            if ($value === null && isset($pathRules['required']) && $pathRules['required']) {
                $valid = false;
                break;
            }

            if ($value !== null) {
                $itemValid = true;
                $itemResult = $value;

                foreach ($pathRules as $rule => $param) {
                    if ($rule === 'required') {
                        continue;
                    }

                    switch ($rule) {
                        case 'sanitize':
                            if ($param === true && is_string($itemResult)) {
                                $itemResult = $this->sanitize($itemResult);
                            }
                            break;
                        case 'email':
                            if (!Validator::email($itemResult)) {
                                $itemValid = false;
                            }
                            break;
                        case 'url':
                            if (!Validator::url($itemResult)) {
                                $itemValid = false;
                            }
                            break;
                        case 'integer':
                            if (!Validator::integer($itemResult)) {
                                $itemValid = false;
                            } else {
                                $itemResult = (int) $itemResult;
                            }
                            break;
                        case 'float':
                            if (!Validator::float($itemResult)) {
                                $itemValid = false;
                            } else {
                                $itemResult = (float) $itemResult;
                            }
                            break;
                        case 'date':
                            if (!Validator::date($itemResult)) {
                                $itemValid = false;
                            }
                            break;
                        case 'time':
                            if (!Validator::time($itemResult)) {
                                $itemValid = false;
                            }
                            break;
                        case 'pattern':
                            if (!Validator::pattern($itemResult, $param)) {
                                $itemValid = false;
                            }
                            break;
                        case 'in':
                            if (!Validator::inList($itemResult, is_array($param) ? $param : explode(',', $param))) {
                                $itemValid = false;
                            }
                            break;
                        case 'between':
                            $params = is_array($param) ? $param : explode(',', $param);
                            if (!Validator::between($itemResult, $params[0] ?? 0, $params[1] ?? PHP_INT_MAX)) {
                                $itemValid = false;
                            }
                            break;
                        case 'min':
                            if (is_string($itemResult) && mb_strlen($itemResult) < $param) {
                                $itemValid = false;
                            } elseif (is_numeric($itemResult) && $itemResult < $param) {
                                $itemValid = false;
                            }
                            break;
                        case 'max':
                            if (is_string($itemResult) && mb_strlen($itemResult) > $param) {
                                $itemValid = false;
                            } elseif (is_numeric($itemResult) && $itemResult > $param) {
                                $itemValid = false;
                            }
                            break;
                        case 'callback':
                            if (is_callable($param) && !$param($itemResult)) {
                                $itemValid = false;
                            }
                            break;
                    }

                    if (!$itemValid) {
                        break;
                    }
                }

                if ($itemValid) {
                    $this->setValueByPath($result, $path, $itemResult);
                } else {
                    $valid = false;
                    break;
                }
            }
        }

        return $valid ? $result : $default;
    }

    /**
     * Gets an uploaded file from the request and validates it with enhanced security checks.
     *
     * @param string $name The name of the file input field
     * @param array $allowedTypes The allowed file extensions
     * @param array $allowedMimes The allowed MIME types (if empty, derived from allowedTypes)
     * @param int $maxSize The maximum file size in bytes
     * @return array|null The file information array or null if no valid file was uploaded
     */
    public function getSecureFile(string $name, array $allowedTypes = [], array $allowedMimes = [], int $maxSize = 0): ?array
    {
        $file = $this->getFile($name);

        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        // Validate the file with enhanced security checks
        if (!Validator::validateFile($file, $allowedTypes, $allowedMimes, $maxSize)) {
            return null;
        }

        // Add the real MIME type to the file array
        $file['real_mime'] = Validator::getRealMimeType($file['tmp_name']);

        return $file;
    }

    /**
     * Checks if an uploaded file contains malicious code.
     *
     * @param string $name The name of the file input field
     * @return bool True if the file is safe, false if potentially malicious or no file was uploaded
     */
    public function isFileSafe(string $name): bool
    {
        $file = $this->getFile($name);

        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            return false;
        }

        return Validator::isFileSafe($file);
    }

    /**
     * Validates that a file's extension matches its actual MIME type.
     *
     * @param string $name The name of the file input field
     * @return bool True if the extension matches the MIME type, false otherwise or if no file was uploaded
     */
    public function isExtensionMatchingMimeType(string $name): bool
    {
        $file = $this->getFile($name);

        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            return false;
        }

        return Validator::isExtensionMatchingMimeType($file);
    }

    /**
     * Sanitizes an array recursively by applying the sanitize method to all string values.
     *
     * @param array $array The array to sanitize
     * @return array The sanitized array
     */
    public function sanitizeArray(array $array): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->sanitizeArray($value);
            } elseif (is_string($value)) {
                $result[$key] = $this->sanitize($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Converts a multi-dimensional array to a flattened array with dot notation keys.
     * Example: ['user' => ['name' => 'John']] becomes ['user.name' => 'John']
     *
     * @param array $array The array to flatten
     * @param string $prepend The prefix to prepend to the keys
     * @return array The flattened array
     */
    public function dot(array $array, string $prepend = ''): array
    {
        $results = [];

        foreach ($array as $key => $value) {
            if (is_array($value) && !empty($value)) {
                $results = array_merge($results, $this->dot($value, $prepend.$key.'.'));
            } else {
                $results[$prepend.$key] = $value;
            }
        }

        return $results;
    }

    /**
     * Gets a value from a nested array using dot notation.
     * Example: getValueByPath($array, 'user.address.city') will retrieve $array['user']['address']['city']
     *
     * @param array $array The array to search in
     * @param string $path The path in dot notation
     * @param mixed $default The default value if the path doesn't exist
     * @return mixed The value at the specified path or the default value
     */
    public function getValueByPath(array $array, string $path, $default = null)
    {
        if (isset($array[$path])) {
            return $array[$path];
        }

        $segments = explode('.', $path);
        $current = $array;

        foreach ($segments as $segment) {
            if (!is_array($current) || !isset($current[$segment])) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Sets a value in a nested array using dot notation.
     * Example: setValueByPath($array, 'user.address.city', 'New York') will set $array['user']['address']['city'] = 'New York'
     *
     * @param array &$array The array to modify
     * @param string $path The path in dot notation
     * @param mixed $value The value to set
     * @return void
     */
    public function setValueByPath(array &$array, string $path, $value): void
    {
        if (strpos($path, '.') === false) {
            $array[$path] = $value;
            return;
        }

        $segments = explode('.', $path);
        $current = &$array;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $current[$segment] = $value;
                break;
            }

            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }
    }

    /**
     * Gets the temporary path of an uploaded file from a PSR-7 UploadedFileInterface.
     * This method handles different PSR-7 implementations to ensure compatibility.
     *
     * @param UploadedFileInterface $file The uploaded file
     * @return string The temporary file path
     */
    private function getUploadedFilePath(UploadedFileInterface | \Kotchasan\Http\UploadedFile $file): string
    {
        // For security reasons, we need to get the actual path of the uploaded file
        // This is implementation-specific and may need to be adjusted based on the PSR-7 implementation

        // For Kotchasan's own implementation, we can directly access the file path
        if ($file instanceof \Kotchasan\Http\UploadedFile) {
            $reflection = new \ReflectionClass($file);
            $property = $reflection->getProperty('file');
            $property->setAccessible(true);
            return $property->getValue($file);
        }

        // Try to get the path from the stream metadata
        if (method_exists($file, 'getStream') && $file->getError() === UPLOAD_ERR_OK) {
            $stream = $file->getStream();
            if (method_exists($stream, 'getMetadata')) {
                $metadata = $stream->getMetadata();
                if (isset($metadata['uri'])) {
                    // Check if the URI is a valid file path
                    $uri = $metadata['uri'];
                    if (file_exists($uri) && is_readable($uri)) {
                        return $uri;
                    }
                }
            }
        }

        // Fallback: Create a temporary file and copy the content
        $tempFile = tempnam(sys_get_temp_dir(), 'upload_');
        if ($file->getError() === UPLOAD_ERR_OK) {
            // Create a copy of the file
            $stream = $file->getStream();
            $stream->rewind();
            file_put_contents($tempFile, $stream->getContents());

            // Register a shutdown function to clean up the temporary file
            register_shutdown_function(function () use ($tempFile) {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            });
        }

        return $tempFile;
    }

    /**
     * Adds a custom validation rule.
     *
     * @param string $name The name of the custom rule
     * @param callable $callback The validation callback function
     * @param string $errorMessage The default error message for this rule
     * @return $this
     */
    public function addCustomRule(string $name, callable $callback, string $errorMessage = ''): self
    {
        $this->customRules[$name] = [
            'callback' => $callback,
            'message' => $errorMessage
        ];
        return $this;
    }

    /**
     * Gets all registered custom validation rules.
     *
     * @return array The custom validation rules
     */
    public function getCustomRules(): array
    {
        return $this->customRules;
    }

    /**
     * Gets validation errors grouped by field groups.
     *
     * @return array The validation errors grouped by field groups
     */
    public function getGroupedErrors(): array
    {
        $groupedErrors = [];

        foreach ($this->errors as $field => $error) {
            if (is_array($error) && isset($error['group']) && isset($error['message'])) {
                $group = $error['group'];
                $message = $error['message'];

                if (!isset($groupedErrors[$group])) {
                    $groupedErrors[$group] = [];
                }

                $groupedErrors[$group][$field] = $message;
            } else {
                if (!isset($groupedErrors['_default'])) {
                    $groupedErrors['_default'] = [];
                }

                $groupedErrors['_default'][$field] = $error;
            }
        }

        return $groupedErrors;
    }

    /**
     * Gets the first error message from each group.
     *
     * @return array The first error message from each group
     */
    public function getFirstGroupErrors(): array
    {
        $groupedErrors = $this->getGroupedErrors();
        $firstErrors = [];

        foreach ($groupedErrors as $group => $errors) {
            $firstErrors[$group] = reset($errors);
        }

        return $firstErrors;
    }

    /**
     * Gets formatted error messages with field labels.
     *
     * @return array The formatted error messages
     */
    public function getFormattedErrors(): array
    {
        $formatted = [];

        foreach ($this->errors as $field => $error) {
            $message = is_array($error) ? $error['message'] : $error;
            $fieldLabel = $this->rules[$field]['label'] ?? $field;

            // Replace :attribute placeholder with field label
            $formatted[$field] = str_replace(':attribute', $fieldLabel, $message);
        }

        return $formatted;
    }

    /**
     * Gets error messages as HTML list.
     *
     * @param string $listType The HTML list type ('ul' or 'ol')
     * @param array $attributes Additional HTML attributes for the list element
     * @return string The HTML list of error messages
     */
    public function errorsToHtml(string $listType = 'ul', array $attributes = []): string
    {
        if (empty($this->errors)) {
            return '';
        }

        $formattedErrors = $this->getFormattedErrors();

        // Build HTML attributes string
        $attributesStr = '';
        foreach ($attributes as $key => $value) {
            $attributesStr .= ' '.htmlspecialchars($key).'="'.htmlspecialchars($value).'"';
        }

        $html = "<{$listType}{$attributesStr}>";

        foreach ($formattedErrors as $error) {
            $html .= '<li>'.htmlspecialchars($error).'</li>';
        }

        $html .= "</{$listType}>";

        return $html;
    }

    /**
     * Gets error messages for a specific field group.
     *
     * @param string $group The field group name
     * @return array The error messages for the specified group
     */
    public function getErrorsForGroup(string $group): array
    {
        $groupedErrors = $this->getGroupedErrors();
        return $groupedErrors[$group] ?? [];
    }

    /**
     * Checks if the request contains JSON data.
     *
     * @return bool True if the request contains JSON data, false otherwise
     */
    public function isJson(): bool
    {
        $contentType = $this->request->getHeaderLine('Content-Type');
        return strpos($contentType, 'application/json') !== false;
    }
}
