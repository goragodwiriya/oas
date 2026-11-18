<?php
/**
 * @filesource Gcms/FormSchema.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms;

use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * FormBuilder Schema Service
 *
 * Shared server-side logic for FormBuilder JSON schemas:
 * - Sanitize schemas received from the builder before persisting
 * - Extract input fields (skipping layout types)
 * - Validate submitted data against the schema (authoritative validation;
 *   client-side HTML5 validation is a convenience only)
 *
 * The schema format is produced by Now/js/FormBuilderManager.js and the
 * field types are defined in Now/js/FieldTypeRegistry.js
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class FormSchema
{
    /**
     * Field types that collect user input
     *
     * @var array
     */
    const INPUT_TYPES = [
        'text', 'email', 'password', 'number', 'tel', 'url', 'search',
        'select', 'multiselect', 'tags', 'radio', 'checkbox',
        'date', 'datetime', 'time', 'range', 'textarea', 'hidden',
        'image', 'file'
    ];

    /**
     * Layout-only field types (render structure, no input)
     *
     * @var array
     */
    const LAYOUT_TYPES = ['fieldset', 'divider', 'row', 'row2', 'row3'];

    /**
     * Field types that submit files instead of scalar values
     *
     * @var array
     */
    const FILE_TYPES = ['image', 'file'];

    /**
     * Maximum number of fields allowed in one schema
     *
     * @var int
     */
    const MAX_FIELDS = 200;

    /**
     * Decode and structurally validate a schema
     *
     * @param string|array $schemaJson JSON string or already-decoded array
     *
     * @return array|null Decoded schema or null if invalid
     */
    public static function decode($schemaJson)
    {
        if (is_string($schemaJson)) {
            $schema = json_decode($schemaJson, true);
        } else {
            $schema = $schemaJson;
        }

        if (!is_array($schema) || !isset($schema['fields']) || !is_array($schema['fields'])) {
            return null;
        }

        return $schema;
    }

    /**
     * Sanitize a schema received from the builder before persisting
     *
     * - Drops fields with unknown types
     * - Enforces field count limit
     * - Sanitizes field name/id (must be safe identifiers)
     * - Sanitizes icon class names
     * - Ensures metadata title/description are plain strings
     *
     * @param array $schema
     *
     * @return array|null Sanitized schema or null if invalid
     */
    public static function sanitize($schema)
    {
        $schema = self::decode($schema);
        if ($schema === null || count($schema['fields']) > self::MAX_FIELDS) {
            return null;
        }

        $allowedTypes = array_merge(self::INPUT_TYPES, self::LAYOUT_TYPES);
        $fields = [];
        $names = [];

        foreach ($schema['fields'] as $field) {
            if (!is_array($field) || empty($field['type']) || !in_array($field['type'], $allowedTypes, true)) {
                continue;
            }

            // Identifier used as input name and DOM id
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($field['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $field['id'] = $id;

            if (isset($field['name'])) {
                $field['name'] = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $field['name']);
            }

            // Duplicate input names would silently lose data on submit
            if (in_array($field['type'], self::INPUT_TYPES, true)) {
                $name = $field['name'] ?? $id;
                if (isset($names[$name])) {
                    continue;
                }
                $names[$name] = true;
            }

            // Icon is rendered as a CSS class
            if (isset($field['icon'])) {
                $field['icon'] = preg_replace('/[^a-z0-9_-]/', '', (string) $field['icon']);
            }

            // Labels are rendered via textContent (XSS-safe) but keep them scalar
            foreach (['label', 'title', 'description', 'placeholder', 'comment'] as $key) {
                if (isset($field[$key]) && !is_scalar($field[$key])) {
                    unset($field[$key]);
                }
            }

            $fields[] = $field;
        }

        $schema['fields'] = $fields;

        // Keep only known metadata keys
        $metadata = is_array($schema['metadata'] ?? null) ? $schema['metadata'] : [];
        $schema['metadata'] = [
            'title' => (string) ($metadata['title'] ?? ''),
            'description' => (string) ($metadata['description'] ?? '')
        ];

        return $schema;
    }

    /**
     * Get input fields from a schema (skipping layout types)
     *
     * @param array $schema
     *
     * @return array List of field configs, keyed by input name
     */
    public static function inputFields($schema)
    {
        $result = [];
        foreach ($schema['fields'] as $field) {
            if (in_array($field['type'] ?? '', self::INPUT_TYPES, true)) {
                $name = $field['name'] ?? $field['id'];
                $result[$name] = $field;
            }
        }
        return $result;
    }

    /**
     * Get allowed option values from a field's options list
     *
     * Options are either plain strings or {value, label} objects
     *
     * @param array $field
     *
     * @return array List of allowed values
     */
    public static function optionValues($field)
    {
        $values = [];
        foreach ((array) ($field['options'] ?? []) as $option) {
            if (is_array($option)) {
                if (isset($option['value'])) {
                    $values[] = (string) $option['value'];
                }
            } elseif (is_scalar($option)) {
                $values[] = (string) $option;
            }
        }
        return $values;
    }

    /**
     * Validate submitted data against a schema
     *
     * File fields (image/file) are NOT handled here; the caller processes
     * uploads separately via $request->getUploadedFiles()
     *
     * @param array $schema
     * @param Request $request
     *
     * @return array [values (array), errors (array keyed by field name)]
     */
    public static function validate($schema, Request $request)
    {
        $values = [];
        $errors = [];

        foreach (self::inputFields($schema) as $name => $field) {
            $type = $field['type'];

            if (in_array($type, self::FILE_TYPES, true)) {
                // Uploads are validated by the caller
                continue;
            }

            if (in_array($type, ['multiselect', 'tags'], true)) {
                $value = $request->post($name, [])->toString();
                $value = is_array($value) ? array_map('strval', $value) : ($value === '' ? [] : [(string) $value]);
                $value = array_values(array_filter($value, static function ($v) {
                    return $v !== '';
                }));
            } else {
                $value = trim($request->post($name)->toString());
            }

            $label = $field['label'] ?? $name;

            // Required check
            if (!empty($field['required']) && ($value === '' || $value === [])) {
                $errors[$name] = Language::replace('Please fill in %s', $label);
                continue;
            }

            // Skip type validation for empty optional values
            if ($value === '' || $value === []) {
                $values[$name] = is_array($value) ? [] : '';
                continue;
            }

            $error = self::validateValue($type, $value, $field);
            if ($error !== null) {
                $errors[$name] = Language::replace($error, $label);
                continue;
            }

            // Length limit (applies to scalar text-like values)
            if (!is_array($value) && !empty($field['maxLength']) && mb_strlen($value) > (int) $field['maxLength']) {
                $value = mb_substr($value, 0, (int) $field['maxLength']);
            }

            $values[$name] = $value;
        }

        return [$values, $errors];
    }

    /**
     * Validate a single value by field type
     *
     * @param string $type
     * @param mixed $value
     * @param array $field
     *
     * @return string|null Error message key (with %s placeholder for label) or null if valid
     */
    private static function validateValue($type, $value, $field)
    {
        switch ($type) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return '%s is invalid';
                }
                break;

            case 'url':
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    return '%s is invalid';
                }
                break;

            case 'number':
            case 'range':
                if (!is_numeric($value)) {
                    return '%s is invalid';
                }
                if (isset($field['min']) && $field['min'] !== '' && $value < (float) $field['min']) {
                    return '%s is invalid';
                }
                if (isset($field['max']) && $field['max'] !== '' && $value > (float) $field['max']) {
                    return '%s is invalid';
                }
                break;

            case 'date':
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    return '%s is invalid';
                }
                break;

            case 'datetime':
                if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $value)) {
                    return '%s is invalid';
                }
                break;

            case 'time':
                if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
                    return '%s is invalid';
                }
                break;

            case 'tel':
                if (!preg_match('/^[0-9+\-\s().]{3,30}$/', $value)) {
                    return '%s is invalid';
                }
                break;

            case 'select':
            case 'radio':
                $allowed = self::optionValues($field);
                if (!empty($allowed) && !in_array($value, $allowed, true)) {
                    return '%s is invalid';
                }
                break;

            case 'multiselect':
                $allowed = self::optionValues($field);
                if (!empty($allowed)) {
                    foreach ($value as $item) {
                        if (!in_array($item, $allowed, true)) {
                            return '%s is invalid';
                        }
                    }
                }
                break;

            case 'checkbox':
                if (!in_array($value, ['0', '1', 'on', (string) ($field['value'] ?? '1')], true)) {
                    return '%s is invalid';
                }
                break;

            case 'text':
            case 'textarea':
            case 'search':
            case 'password':
            case 'hidden':
            case 'tags':
                if (!empty($field['pattern']) && !is_array($value)) {
                    // Pattern comes from the schema author (form owner), not the submitter
                    $pattern = '/'.str_replace('/', '\/', $field['pattern']).'/u';
                    if (@preg_match($pattern, '') === false) {
                        break; // Invalid regex in schema; skip pattern check
                    }
                    if (!preg_match($pattern, $value)) {
                        return '%s is invalid';
                    }
                }
                break;
        }

        return null;
    }

    /**
     * Format a stored submission value for display/export
     *
     * @param mixed $value
     *
     * @return string
     */
    public static function formatValue($value)
    {
        if (is_array($value)) {
            return implode(', ', array_map([self::class, 'formatValue'], $value));
        }
        return (string) $value;
    }
}
