<?php
/**
 * @filesource Kotchasan/Form.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Form class
 *
 * @see https://www.kotchasan.com/
 */
class Form extends \Kotchasan\KBase
{
    /**
     * Variable to indicate whether Ajax form is being used or not.
     * GAjax must be called if this is set to true.
     *
     * @var bool
     */
    public $ajax = false;

    /**
     * Variable to indicate whether the form is being used with GForm or not.
     * GAjax must be called if this is set to true.
     *
     * @var bool
     */
    public $gform = true;

    /**
     * JavaScript
     *
     * @var array
     */
    public $javascript;

    /**
     * Tag attributes
     *
     * @var array
     */
    private $attributes;

    /**
     * Tag name
     *
     * @var string
     */
    private $tag;

    /**
     * Create a button element.
     *
     * @assert (array('id' => 'test_id', 'value' => 'Test', 'disabled' => true))->render() [==] '<button disabled type="button" name="test_id" id="test_id">Test</button>'
     * @assert (array('id' => 'test_id', 'value' => 'Test', 'disabled' => true, 'tag' => 'input'))->render() [==] '<input disabled type="button" name="test_id" id="test_id" value="Test">'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function button($attributes = [])
    {
        $obj = new static;
        if (isset($attributes['tag']) && $attributes['tag'] == 'input') {
            $obj->tag = 'input';
        } else {
            $obj->tag = 'button';
        }
        unset($attributes['tag']);
        $attributes['type'] = 'button';
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Create an input element of type "checkbox".
     *
     * @assert (array('id' => 'test_id', 'value' => 1))->render() [==] '<input type="checkbox" name="test_id" id="test_id" value=1>'
     * @assert (array('id' => 'test_id', 'value' => 1, 'label' => 'Test'))->render() [==] '<label><input type="checkbox" name="test_id" id="test_id" value=1 title="Test">Test</label>'
     * @assert (array('id' => 'test_id', 'value' => 1, 'label' => 'Test', 'itemClass' => 'item'))->render() [==] '<div class="item"><label><input type="checkbox" name="test_id" id="test_id" value=1 title="Test">&nbsp;Test</label></div>'
     * @assert (array('id' => 'test_id', 'value' => 1, 'label' => 'Test', 'itemClass' => 'item' , 'labelClass' => 'icon-valid'))->render() [==] '<div class="item"><label class="icon-valid"><input type="checkbox" name="test_id" id="test_id" value=1 title="Test">&nbsp;Test</label></div>'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function checkbox($attributes = [])
    {
        $obj = new static;
        $obj->tag = 'input';
        $attributes['type'] = 'checkbox';
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Create an input element of type "color".
     *
     * @assert (array('id' => 'test_id', 'value' => '#FFF', 'label' => 'Test'))->render() [==] '<label>Test&nbsp;<input type="text" name="test_id" id="test_id" value="#FFF" title="Test" class="color"></label>'
     * @assert (array('id' => 'test_id', 'value' => '#FFF', 'label' => 'Test', 'itemClass' => 'item' , 'labelClass' => 'icon-color'))->render() [==] '<div class="item"><label for="test_id">Test</label><span class="icon-color"><input type="text" name="test_id" id="test_id" value="#FFF" title="Test" class="color"></span></div>'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function color($attributes = [])
    {
        $obj = new static;
        $obj->tag = 'input';
        $attributes['type'] = 'text';
        $attributes['class'] = 'color';
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Create an input element for handling currency values.
     *
     * @assert (array('id' => 'test_id', 'value' => 1, 'label' => 'Test'))->render() [==] '<label>Test&nbsp;<input type="text" name="test_id" id="test_id" value=1 title="Test" class="currency"></label>'
     * @assert (array('id' => 'test_id', 'value' => 100, 'label' => 'Test', 'itemClass' => 'item' , 'labelClass' => 'icon-money'))->render() [==] '<div class="item"><label for="test_id">Test</label><span class="icon-money"><input type="text" name="test_id" id="test_id" value=100 title="Test" class="currency"></span></div>'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function currency($attributes = [])
    {
        $obj = new static;
        $obj->tag = 'input';
        $attributes['type'] = 'text';
        $attributes['class'] = 'currency';
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Create an input element of type "date".
     *
     * @assert (array('id' => 'test_id', 'value' => 1, 'label' => 'Test'))->render() [==] '<label>Test&nbsp;<input type="date" name="test_id" id="test_id" value=1 title="Test"></label>'
     * @assert (array('id' => 'test_id', 'value' => '#FFF', 'label' => 'Test', 'itemClass' => 'item' , 'labelClass' => 'icon-calendar'))->render() [==] '<div class="item"><label for="test_id">Test</label><span class="icon-calendar"><input type="date" name="test_id" id="test_id" value="#FFF" title="Test"></span></div>'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function date($attributes = [])
    {
        $obj = new static;
        $obj->tag = 'input';
        $attributes['type'] = 'date';
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Create an input element of type "datetime".
     *
     * @assert (array('id' => 'test_id', 'value' => '2021-01-01 12:00', 'label' => 'Test'))->render() [==] '<label>Test&nbsp;<input type="datetime-local" name="test_id" id="test_id" value="2021-01-01 12:00" title="Test"></label>'
     * @assert (array('id' => 'test_id', 'value' => '2021-01-01 12:00', 'label' => 'Test', 'itemClass' => 'item' , 'labelClass' => 'icon-calendar'))->render() [==] '<div class="item"><label for="test_id">Test</label><span class="icon-calendar"><input type="datetime-local" name="test_id" id="test_id" value="2021-01-01 12:00" title="Test"></span></div>'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function datetime($attributes = [])
    {
        $obj = new static;
        $obj->tag = 'input';
        $attributes['type'] = 'datetime-local';
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Create an input element of type "email".
     *
     * @assert (array('id' => 'test_id', 'label' => 'Test'))->render() [==] '<label>Test&nbsp;<input type="email" name="test_id" id="test_id" title="Test"></label>'
     * @assert (array('id' => 'test_id', 'label' => 'Test', 'itemClass' => 'item' , 'labelClass' => 'icon-email'))->render() [==] '<div class="item"><label for="test_id">Test</label><span class="icon-email"><input type="email" name="test_id" id="test_id" title="Test"></span></div>'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function email($attributes = [])
    {
        $obj = new static;
        $obj->tag = 'input';
        $attributes['type'] = 'email';
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Create an input element of type "file".
     *
     * @assert (array('id' => 'test_id', 'label' => 'Test'))->render() [==] '<label>Test&nbsp;<input type="file" name="test_id" id="test_id" title="Test" class="g-file"></label>'
     * @assert (array('id' => 'test_id', 'label' => 'Test', 'itemClass' => 'item' , 'labelClass' => 'icon-file'))->render() [==] '<div class="item"><label for="test_id">Test</label><span class="icon-file"><input type="file" name="test_id" id="test_id" title="Test" class="g-file"></span></div>'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function file($attributes = [])
    {
        $obj = new static;
        $obj->tag = 'input';
        $attributes['type'] = 'file';
        $attributes['class'] = 'g-file';
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Retrieve input values from query parameters and parsed body.
     *
     * @return array An array of hidden input elements.
     */
    public static function get2Input()
    {
        $hiddens = [];

        // Retrieve input values from query parameters
        foreach (self::$request->getQueryParams() as $key => $value) {
            // Exclude empty values and keys containing sensitive information
            if ($value != '' && !preg_match('/.*?(username|password|token|time).*?/', $key) && preg_match('/^[_]+([^0-9]+)$/', $key, $match)) {
                $hiddens[$match[1]] = '<input type="hidden" name="_'.$match[1].'" value="'.htmlspecialchars($value).'">';
            }
        }

        // Retrieve input values from parsed body
        foreach (self::$request->getParsedBody() as $key => $value) {
            // Exclude empty values and keys containing sensitive information
            if ($value != '' && !preg_match('/.*?(username|password|token|time).*?/', $key) && preg_match('/^[_]+([^0-9]+)$/', $key, $match)) {
                $hiddens[$match[1]] = '<input type="hidden" name="_'.$match[1].'" value="'.htmlspecialchars($value).'">';
            }
        }

        return $hiddens;
    }

    /**
     * Create an input element of type "hidden".
     *
     * @assert (array('id' => 'test_id', 'value' => 1))->render() [==] '<input type="hidden" name="test_id" id="test_id" value=1>'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function hidden($attributes = [])
    {
        $obj = new static;
        $obj->tag = 'input';
        $attributes['type'] = 'hidden';
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Create an input element of type "number".
     *
     * @assert (array('id' => 'test_id', 'value' => 1, 'label' => 'Test'))->render() [==] '<label>Test&nbsp;<input type="number" name="test_id" id="test_id" value=1 title="Test"></label>'
     * @assert (array('id' => 'test_id', 'value' => 1234, 'label' => 'Test', 'itemClass' => 'item' , 'labelClass' => 'icon-number'))->render() [==] '<div class="item"><label for="test_id">Test</label><span class="icon-number"><input type="number" name="test_id" id="test_id" value=1234 title="Test"></span></div>'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function number($attributes = [])
    {
        $obj = new static;
        $obj->tag = 'input';
        $attributes['type'] = 'number';
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Create an input element of type "integer" that allows negative values.
     *
     * @assert (array('id' => 'test_id', 'value' => 1, 'label' => 'Test'))->render() [==] '<label>Test&nbsp;<input type="integer" name="test_id" id="test_id" value=1 title="Test"></label>'
     * @assert (array('id' => 'test_id', 'value' => -100, 'label' => 'Test', 'itemClass' => 'item' , 'labelClass' => 'icon-number'))->render() [==] '<div class="item"><label for="test_id">Test</label><span class="icon-number"><input type="integer" name="test_id" id="test_id" value=-100 title="Test"></span></div>'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function integer($attributes = [])
    {
        $obj = new static;
        $obj->tag = 'input';
        $attributes['type'] = 'integer';
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Create an input element of type "password".
     *
     * @assert (array('id' => 'test_id', 'value' => '1234', 'label' => 'Test'))->render() [==] '<label>Test&nbsp;<input type="password" name="test_id" id="test_id" value=1234 title="Test"></label>'
     * @assert (array('id' => 'test_id', 'value' => '1234', 'label' => 'Test', 'itemClass' => 'item' , 'labelClass' => 'icon-color'))->render() [==] '<div class="item"><label for="test_id">Test</label><span class="icon-color"><input type="password" name="test_id" id="test_id" value=1234 title="Test"></span></div>'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function password($attributes = [])
    {
        $obj = new static;
        $obj->tag = 'input';
        $attributes['type'] = 'password';
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Create an input element of type "radio".
     *
     * @assert (array('id' => 'test_id', 'value' => 1))->render() [==] '<input type="radio" name="test_id" id="test_id" value=1>'
     * @assert (array('id' => 'test_id', 'value' => 1, 'label' => 'Test'))->render() [==] '<label><input type="radio" name="test_id" id="test_id" value=1 title="Test">Test</label>'
     * @assert (array('id' => 'test_id', 'value' => 1, 'label' => 'Test', 'itemClass' => 'item'))->render() [==] '<div class="item"><label for="test_id">Test</label><span><input type="radio" name="test_id" id="test_id" value=1 title="Test"></span></div>'
     * @assert (array('id' => 'test_id', 'value' => 1, 'label' => 'Test', 'itemClass' => 'item' , 'labelClass' => 'icon-valid'))->render() [==] '<div class="item"><label for="test_id">Test</label><span class="icon-valid"><input type="radio" name="test_id" id="test_id" value=1 title="Test"></span></div>'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function radio($attributes = [])
    {
        $obj = new static;
        $obj->tag = 'input';
        $attributes['type'] = 'radio';
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Create an input element of type "range".
     *
     * @assert (array('id' => 'test_id', 'value' => 1, 'label' => 'Test'))->render() [==] '<label>Test&nbsp;<input type="range" name="test_id" id="test_id" value=1 title="Test"></label>'
     * @assert (array('id' => 'test_id', 'value' => 1, 'label' => 'Test', 'itemClass' => 'item'))->render() [==] '<div class="item"><label for="test_id">Test</label><div><input type="range" name="test_id" id="test_id" value=1 title="Test"></div></div>'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function range($attributes = [])
    {
        $obj = new static;
        $obj->tag = 'input';
        $attributes['type'] = 'range';
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Function for generating form elements.
     *
     * The function supports the following parameters:
     * - id, name, type: properties of the input element.
     * - options: for select elements only, an array of options in the format array('value1' => 'name1', 'value2' => 'name2', ...).
     * - datalist: for text input elements, an array of options in the format array('value1' => 'name1', 'value2' => 'name2', ...).
     * - label: the label text to display for the input element.
     * - labelClass: the CSS class of the label.
     * - comment: if specified, displays a description of the input.
     *
     * If neither label nor labelClass is specified, only the input element will be created.
     *
     * @param string $tag The HTML tag for the element.
     * @param array  $param The properties of the input element.
     * @param string $options The options for select elements.
     *
     * @return string The generated HTML for the form element.
     */
    public function render()
    {
        $prop = [];
        $event = [];
        $class = [];
        foreach ($this->attributes as $k => $v) {
            switch ($k) {
                case 'itemClass':
                case 'itemId':
                case 'labelClass':
                case 'label':
                case 'comment':
                case 'unit':
                case 'value':
                case 'dataPreview':
                case 'previewSrc':
                case 'accept':
                case 'options':
                case 'optgroup':
                case 'multiple':
                case 'validator':
                case 'autofocus':
                case 'result':
                case 'checked':
                case 'datalist':
                case 'button':
                    $$k = $v;
                    break;
                case 'showpassword':
                    $class[] = 'showpassword';
                    break;
                case 'class':
                    $class[] = $v;
                    break;
                case 'title':
                    $prop['title'] = 'title="'.strip_tags($v).'"';
                    break;
                default:
                    if ($k == 'id') {
                        $id = $v;
                    } elseif (is_numeric($k)) {
                        $prop[$v] = $v;
                    } elseif ($v === true) {
                        $prop[$k] = $k;
                    } elseif ($v === false) {
                    } elseif (preg_match('/^on([a-z]+)/', $k, $match)) {
                        $event[$match[1]] = $v;
                    } elseif (!is_array($v)) {
                        $prop[$k] = $k.'="'.$v.'"';
                        $$k = $v;
                    }
                    break;
            }
        }
        if (isset($id)) {
            if (empty($name)) {
                $name = $id;
                $prop['name'] = 'name="'.$name.'"';
            }
            $id = trim(preg_replace('/[\[\]]+/', '_', $id), '_');
            $prop['id'] = 'id="'.$id.'"';
        } else {
            $id = '';
        }
        if (isset(Html::$form)) {
            if ($id != '' && Html::$form->gform) {
                if (isset($validator)) {
                    $js = [];
                    $js[] = '"'.$id.'"';
                    $js[] = '"'.$validator[0].'"';
                    $js[] = $validator[1];
                    if (isset($validator[2])) {
                        $js[] = '"'.$validator[2].'"';
                        $js[] = empty($validator[3]) || $validator[3] === null ? 'null' : '"'.$validator[3].'"';
                        $js[] = '"'.Html::$form->attributes['id'].'"';
                    }
                    $this->javascript[] = 'new GValidator('.implode(', ', $js).');';
                    unset($validator);
                }
                foreach ($event as $on => $func) {
                    $this->javascript[] = '$G("'.$id.'").addEvent("'.$on.'", '.$func.');';
                }
            } elseif (!Html::$form->gform) {
                foreach ($event as $on => $func) {
                    $prop['on'.$on] = 'on'.$on.'="'.$func.'()"';
                }
            }
        }
        if ($this->tag == 'select') {
            unset($prop['type']);
            if (!isset($value)) {
                $value = isset($multiple) ? [] : null;
            }
            if (isset($options) && is_array($options)) {
                $datas = [];
                foreach ($options as $k => $v) {
                    if (is_array($value)) {
                        $sel = in_array($k, $value) ? ' selected' : '';
                    } else {
                        $sel = $value == $k ? ' selected' : '';
                    }
                    if (is_numeric($k)) {
                        $datas[] = '<option value='.$k.$sel.'>'.$v.'</option>';
                    } else {
                        $datas[] = '<option value="'.$k.'"'.$sel.'>'.$v.'</option>';
                    }
                }
                $value = implode('', $datas);
            } elseif (isset($optgroup) && is_array($optgroup)) {
                $datas = [];
                foreach ($optgroup as $group_label => $options) {
                    $datas[] = '<optgroup label="'.$group_label.'">';
                    foreach ($options as $k => $v) {
                        if (is_array($value)) {
                            $sel = in_array($k, $value) ? ' selected' : '';
                        } else {
                            $sel = $value == $k ? ' selected' : '';
                        }
                        $datas[] = '<option value="'.$k.'"'.$sel.'>'.$v.'</option>';
                    }
                    $datas[] = '</optgroup>';
                }
                $value = implode('', $datas);
            }
        } elseif (isset($value)) {
            if ($this->tag === 'textarea') {
                $value = str_replace(array('{', '}', '&amp;'), array('&#x007B;', '&#x007D;', '&'), htmlspecialchars($value));
            } elseif ($this->tag != 'button') {
                if (is_numeric($value)) {
                    $prop['value'] = 'value='.$value;
                } elseif (is_string($value)) {
                    $prop['value'] = 'value="'.str_replace('&amp;', '&', htmlspecialchars($value)).'"';
                } else {
                    throw new \Exception('Value of "'.(isset($name) ? $name : '').'" must be numbers and letters only.');
                }
            }
        }
        if (empty($prop['title'])) {
            if (!empty($comment)) {
                $prop['title'] = 'title="'.strip_tags($comment).'"';
            } elseif (!empty($label)) {
                $prop['title'] = 'title="'.strip_tags($label).'"';
            }
        }
        if (isset($dataPreview)) {
            $prop['data-preview'] = 'data-preview="'.$dataPreview.'"';
        }
        if (isset($result)) {
            $prop['data-result'] = 'data-result="result_'.$result.'"';
        }
        if (isset($accept) && is_array($accept)) {
            $prop['accept'] = 'accept="'.Mime::getAccept($accept).'"';
        }
        if (isset($multiple)) {
            $prop['multiple'] = 'multiple';
        }
        if (isset($checked) && isset($value) && $checked == $value) {
            $prop['checked'] = 'checked';
        }
        if (isset($datalist) && is_array($datalist)) {
            if (empty($prop['list'])) {
                $list = $id.'-datalist';
            } else {
                $list = $prop['list'];
            }
            $prop['list'] = 'list="'.$list.'"';
            $prop['autocomplete'] = 'autocomplete="off"';
        }
        if (!empty($class)) {
            $prop['class'] = 'class="'.implode(' ', $class).'"';
        }
        if (empty($prop['checkbox']) || $this->tag === 'select') {
            $w_checkbox = '';
        } else {
            $itemClass = empty($itemClass) ? 'w_checkbox' : $itemClass.' w_checkbox';
            $checked = empty($value) ? '' : ' checked';
            $w_checkbox = '<input type=checkbox id="checkbox_'.$id.'" name="checkbox_'.$name.'" value=1'.$checked.'>';
        }
        if ($this->tag == 'input') {
            $element = '<'.$this->tag.' '.implode(' ', $prop).'>';
        } elseif (isset($value)) {
            $element = '<'.$this->tag.' '.implode(' ', $prop).'>'.$value.'</'.$this->tag.'>';
        } else {
            $element = '<'.$this->tag.' '.implode(' ', $prop).'></'.$this->tag.'>';
        }
        if (isset($datalist) && is_array($datalist)) {
            $element .= '<datalist id="'.$list.'">';
            foreach ($datalist as $k => $v) {
                if (is_numeric($k)) {
                    $element .= '<option value='.$k.'>'.$v.'</option>';
                } else {
                    $element .= '<option value="'.$k.'">'.$v.'</option>';
                }
            }
            $element .= '</datalist>';
        }
        if (empty($itemClass)) {
            $input = empty($comment) ? '' : '<div class="item"'.(empty($itemId) ? '' : ' id="'.$itemId.'"').'>';
            $input = empty($unit) ? '' : '<div class="wlabel">';
            if (empty($labelClass) && empty($label)) {
                $input .= $element;
            } elseif (isset($type) && ($type === 'checkbox' || $type === 'radio')) {
                if (!empty($button)) {
                    $label = '<span>'.$label.'</span>';
                }
                $input .= self::create('label', '', (empty($labelClass) ? '' : $labelClass), $element.$label);
            } else {
                $input .= self::create('label', '', (empty($labelClass) ? '' : $labelClass), (empty($label) ? '' : $label.'&nbsp;').$element);
            }
            if (!empty($unit)) {
                $input .= '<span class="label">'.$unit.'</span></div>';
            }
            if (!empty($comment)) {
                $input .= self::create('div', (empty($id) ? '' : 'result_'.$id), 'comment', $comment);
            }
        } else {
            $input = '<div class="'.$itemClass.'"'.(empty($itemId) ? '' : ' id="'.$itemId.'"').'>';
            if (isset($type) && $type === 'checkbox') {
                $input .= self::create('label', '', (empty($labelClass) ? '' : $labelClass), $element.'&nbsp;'.(isset($label) ? $label : ''));
            } else {
                if (isset($dataPreview)) {
                    $input .= '<div class="file-preview" id="'.$dataPreview.'">';
                    if (isset($previewSrc)) {
                        if (preg_match_all('/\.([a-z0-9]+)(\?|$)/i', $previewSrc, $match)) {
                            $ext = strtoupper($match[1][0]);
                            if (in_array($ext, array('JPG', 'JPEG', 'GIF', 'PNG', 'BMP', 'WEBP', 'TIFF', 'ICO'))) {
                                $input .= '<a href="'.$previewSrc.'" target="preview" class="file-thumb" style="background-image:url('.$previewSrc.')"></a>';
                            } else {
                                $input .= '<a href="'.$previewSrc.'" target="preview" class="file-thumb">'.$ext.'</a>';
                            }
                        }
                    }
                    $input .= '</div>';
                }
                if (isset($label) && isset($id)) {
                    $input .= '<label for="'.$id.'">'.$label.'</label>';
                }
                if (!empty($unit)) {
                    $input .= '<div class=wlabel>';
                }
                $input .= $w_checkbox;
                $labelClass = isset($labelClass) ? $labelClass : '';
                if (isset($type) && $type === 'range') {
                    $input .= self::create('div', '', $labelClass, $element);
                } elseif (isset($label) && isset($id)) {
                    $input .= self::create('span', '', $labelClass, $element);
                } else {
                    $input .= self::create('label', '', $labelClass, $element);
                }
                if (!empty($unit)) {
                    $input .= self::create('span', '', 'label', $unit).'</div>';
                }
            }
            if (!empty($comment)) {
                $input .= self::create('div', (empty($id) ? '' : 'result_'.$id), 'comment', $comment);
            }
            $input .= '</div>';
        }
        if (!empty($autofocus) && !empty($id)) {
            $this->javascript[] = '$E("'.$id.'").focus();';
        }
        return $input;
    }

    /**
     * Creates an HTML element with the specified attributes and inner HTML.
     *
     * @param string $elem The HTML element tag name
     * @param string $id The value of the 'id' attribute
     * @param string $class The value of the 'class' attribute
     * @param string $innerHTML The inner HTML content of the element
     *
     * @return string The generated HTML element
     */
    private static function create($elem, $id, $class, $innerHTML)
    {
        $element = '<'.$elem;
        if ($id != '') {
            $element .= ' id="'.$id.'"';
        }
        if ($class != '') {
            $element .= ' class="'.$class.'"';
        }
        return $element.'>'.$innerHTML.'</'.$elem.'>';
    }

    /**
     * Creates a reset button element.
     *
     * @assert (array('id' => 'test_id', 'value' => 'Test', 'disabled' => true))->render() [==] '<button disabled type="reset" name="test_id" id="test_id">Test</button>'
     * @assert (array('id' => 'test_id', 'value' => 'Test', 'disabled' => true, 'tag' => 'input'))->render() [==] '<input disabled type="reset" name="test_id" id="test_id" value="Test">'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function reset($attributes = [])
    {
        $obj = new static;
        if (isset($attributes['tag']) && $attributes['tag'] == 'input') {
            $obj->tag = 'input';
        } else {
            $obj->tag = 'button';
        }
        unset($attributes['tag']);
        $attributes['type'] = 'reset';
        if (isset($attributes['name']) && $attributes['name'] == 'reset') {
            unset($attributes['name']);
        }
        if (isset($attributes['id']) && $attributes['id'] == 'reset') {
            unset($attributes['id']);
        }
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Creates a select dropdown element.
     *
     * @assert (array('id' => 'test_id', 'value' => 1, 'label' => 'Test', 'options' => [0 => 0, 1 => 1]))->render() [==] '<label>Test&nbsp;<select name="test_id" id="test_id" title="Test"><option value=0>0</option><option value=1 selected>1</option></select></label>'
     * @assert (array('id' => 'test_id', 'value' => '#FFF', 'label' => 'Test', 'itemClass' => 'item' , 'labelClass' => 'icon-color', 'options' => [0 => 0, 1 => 1]))->render() [==] '<div class="item"><label for="test_id">Test</label><span class="icon-color"><select name="test_id" id="test_id" title="Test"><option value=0 selected>0</option><option value=1>1</option></select></span></div>'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function select($attributes = [])
    {
        $obj = new static;
        $obj->tag = 'select';
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Creates a submit button or input field.
     *
     * @assert (array('id' => 'test_id', 'value' => 'Test', 'disabled' => true))->render() [==] '<button disabled type="submit" name="test_id" id="test_id">Test</button>'
     * @assert (array('id' => 'test_id', 'value' => 'Test', 'disabled' => true, 'tag' => 'input'))->render() [==] '<input disabled type="submit" name="test_id" id="test_id" value="Test">'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function submit($attributes = [])
    {
        $obj = new static;
        if (isset($attributes['tag']) && $attributes['tag'] == 'input') {
            $obj->tag = 'input';
        } else {
            $obj->tag = 'button';
        }
        unset($attributes['tag']);
        $attributes['type'] = 'submit';
        if (isset($attributes['name']) && $attributes['name'] == 'submit') {
            unset($attributes['name']);
        }
        if (isset($attributes['id']) && $attributes['id'] == 'submit') {
            unset($attributes['id']);
        }
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Create an input element of type "tel".
     *
     * @assert (array('id' => 'test_id', 'value' => '0123456789', 'label' => 'Test'))->render() [==] '<label>Test&nbsp;<input type="tel" name="test_id" id="test_id" value=0123456789 title="Test"></label>'
     * @assert (array('id' => 'test_id', 'value' => '0123456789', 'label' => 'Test', 'itemClass' => 'item' , 'labelClass' => 'icon-phone'))->render() [==] '<div class="item"><label for="test_id">Test</label><span class="icon-phone"><input type="tel" name="test_id" id="test_id" value=0123456789 title="Test"></span></div>'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function tel($attributes = [])
    {
        $obj = new static;
        $obj->tag = 'input';
        $attributes['type'] = 'tel';
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Create an input element of type "text".
     *
     * @assert (array('id' => 'test_id', 'value' => 1, 'label' => 'Test'))->render() [==] '<label>Test&nbsp;<input type="text" name="test_id" id="test_id" value=1 title="Test"></label>'
     * @assert (array('id' => 'test_id', 'value' => '#FFF', 'label' => 'Test', 'itemClass' => 'item' , 'labelClass' => 'icon-color'))->render() [==] '<div class="item"><label for="test_id">Test</label><span class="icon-color"><input type="text" name="test_id" id="test_id" value="#FFF" title="Test"></span></div>'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function text($attributes = [])
    {
        $obj = new static;
        $obj->tag = 'input';
        $attributes['type'] = 'text';
        if (isset($attributes['text'])) {
            if ($attributes['text'] === true) {
                $attributes['text'] = '';
            } elseif ($attributes['text'] === false) {
                unset($attributes['text']);
            }
        }
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Create an input element of type "textarea".
     *
     * @assert (array('id' => 'test_id', 'value' => 1, 'label' => 'Test'))->render() [==] '<label>Test&nbsp;<textarea name="test_id" id="test_id" title="Test">1</textarea></label>'
     * @assert (array('id' => 'test_id', 'value' => '#FFF', 'label' => 'Test', 'itemClass' => 'item' , 'labelClass' => 'icon-file'))->render() [==] '<div class="item"><label for="test_id">Test</label><span class="icon-file"><textarea name="test_id" id="test_id" title="Test">#FFF</textarea></span></div>'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function textarea($attributes = [])
    {
        $obj = new static;
        $obj->tag = 'textarea';
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Create an input element of type "time".
     *
     * @assert (array('id' => 'test_id', 'value' => '00:00', 'label' => 'Test'))->render() [==] '<label>Test&nbsp;<input type="time" name="test_id" id="test_id" value="00:00" title="Test"></label>'
     * @assert (array('id' => 'test_id', 'value' => '00:00', 'label' => 'Test', 'itemClass' => 'item' , 'labelClass' => 'icon-clock'))->render() [==] '<div class="item"><label for="test_id">Test</label><span class="icon-clock"><input type="time" name="test_id" id="test_id" value="00:00" title="Test"></span></div>'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function time($attributes = [])
    {
        $obj = new static;
        $obj->tag = 'input';
        $attributes['type'] = 'time';
        $obj->attributes = $attributes;
        return $obj;
    }

    /**
     * Create an input element of type "url".
     *
     * @assert (array('id' => 'test_id', 'value' => 'kotchasan.com', 'label' => 'Test'))->render() [==] '<label>Test&nbsp;<input type="url" name="test_id" id="test_id" value="kotchasan.com" title="Test"></label>'
     * @assert (array('id' => 'test_id', 'value' => 'kotchasan.com', 'label' => 'Test', 'itemClass' => 'item' , 'labelClass' => 'icon-world'))->render() [==] '<div class="item"><label for="test_id">Test</label><span class="icon-world"><input type="url" name="test_id" id="test_id" value="kotchasan.com" title="Test"></span></div>'
     *
     * @param array $attributes
     *        An array of attributes for the input field.
     *
     * @return static
     */
    public static function url($attributes = [])
    {
        $obj = new static;
        $obj->tag = 'input';
        $attributes['type'] = 'url';
        $obj->attributes = $attributes;
        return $obj;
    }
}
