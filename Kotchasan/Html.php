<?php
/**
 * @filesource Kotchasan/Html.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * HTML class
 *
 * @see https://www.kotchasan.com/
 */
class Html extends \Kotchasan\KBase
{
    /**
     * Tag attributes
     *
     * @var array
     */
    public $attributes;
    /**
     * Form object variable
     *
     * @var \Kotchasan\Form
     */
    public static $form;
    /**
     * JavaScript
     *
     * @var array
     */
    protected $javascript;
    /**
     * Array of data within the tag
     *
     * @var array
     */
    protected $rows;
    /**
     * Tag name
     *
     * @var string
     */
    protected $tag;

    /**
     * Class constructor
     */
    public function __construct($tag, $attributes = [])
    {
        $this->tag = strtolower($tag);
        $this->attributes = $attributes;
        $this->rows = [];
        $this->javascript = [];
    }

    /**
     * Insert a tag into the element like using innerHTML
     *
     * @param string $tag
     * @param array  $attributes
     *
     * @return static
     */
    public function add($tag, $attributes = [])
    {
        $tag = strtolower($tag);
        if ($tag == 'groups' || $tag == 'groups-table') {
            $obj = $this->addGroups($tag, $attributes);
        } elseif ($tag == 'inputgroups') {
            $obj = $this->addInputGroups($attributes);
        } elseif ($tag == 'radiogroups' || $tag == 'checkboxgroups') {
            $obj = $this->addRadioOrCheckbox($tag, $attributes);
        } elseif ($tag == 'menubutton') {
            $obj = $this->addMenuButton($attributes);
        } elseif ($tag == 'ckeditor') {
            $obj = $this->addCKEditor($tag, $attributes);
        } elseif ($tag == 'row') {
            $obj = new static('div', array(
                'class' => 'row'
            ));
            $this->rows[] = $obj;
        } elseif ($tag == 'rowgroup') {
            $obj = new static('div', array(
                'class' => 'rowgroup'
            ));
            $this->rows[] = $obj;
        } else {
            $obj = self::create($tag, $attributes);
            $this->rows[] = $obj;
        }
        return $obj;
    }

    /**
     * Append HTML to the element at the end position
     *
     * @param string $html
     */
    public function appendChild($html)
    {
        $this->rows[] = $html;
    }

    /**
     * Create a new Element
     *
     * @param string $tag
     * @param array  $attributes
     *
     * @return static
     */
    public static function create($tag, $attributes = [])
    {
        if (method_exists(__CLASS__, $tag)) {
            $obj = self::$tag($attributes);
        } elseif (method_exists('Kotchasan\Form', $tag)) {
            $obj = \Kotchasan\Form::$tag($attributes);
        } else {
            $obj = new static($tag, $attributes);
        }
        return $obj;
    }

    /**
     * Create a fieldset element.
     *
     * @param array $attributes The attributes of the fieldset element.
     *
     * @return self The created fieldset element.
     */
    public static function fieldset($attributes = [])
    {
        $prop = [];
        $span = [];

        foreach ($attributes as $key => $value) {
            if ($key == 'title') {
                $span['innerHTML'] = $value;
            } elseif ($key == 'titleClass') {
                $span['class'] = $value;
            } else {
                $prop[$key] = $value;
            }
        }

        $obj = new static('fieldset', $prop);

        if (isset($span['innerHTML'])) {
            $legend = $obj->add('legend');
            $legend->add('span', $span);
        }

        return $obj;
    }

    /**
     * Create a form element.
     *
     * @param array $attributes The attributes of the form element.
     *
     * @return self The created form element.
     */
    public static function form($attributes = [])
    {
        $ajax = false;
        $prop = array('method' => 'post');
        $gform = true;
        $token = false;

        foreach ($attributes as $key => $value) {
            if (
                $key === 'ajax' || $key === 'action' || $key === 'onsubmit' || $key === 'onbeforesubmit' ||
                $key === 'elements' || $key === 'script' || $key === 'gform' || $key === 'token'
            ) {
                $$key = $value;
            } else {
                $prop[$key] = $value;
            }
        }

        if (isset($prop['id']) && $gform) {
            $script = 'new GForm("'.$prop['id'].'"';
            if (isset($action)) {
                if ($ajax) {
                    $script .= ', "'.$action.'"';
                    if (isset($onbeforesubmit)) {
                        $script .= ',null ,false , function(){return '.$onbeforesubmit.'}';
                    }
                } else {
                    $prop['action'] = $action;
                }
            }
            $script .= ')';
            if (isset($onsubmit)) {
                $script .= '.onsubmit('.$onsubmit.')';
            }
            $script .= ';';
            $form_inputs = Form::get2Input();
        } else {
            if (isset($action)) {
                $prop['action'] = $action;
            }
            if (isset($onsubmit)) {
                $prop['onsubmit'] = $onsubmit.'()';
            }
            if (isset($onbeforesubmit)) {
                $prop['onbeforesubmit'] = $onbeforesubmit.'()';
            }
        }

        self::$form = new static('form', $prop);
        self::$form->ajax = $ajax;
        self::$form->gform = $gform;

        if (!empty($form_inputs)) {
            self::$form->rows = $form_inputs;
        }

        if ($token) {
            self::$form->rows[] = '<input type=hidden name=token id=token value="'.self::$request->createToken().'">';
        }

        if (isset($script)) {
            self::$form->javascript[] = $script;
        }

        return self::$form;
    }

    /**
     * Generate an HTML element with the specified inner HTML content.
     *
     * @param string $html The inner HTML content.
     *
     * @return string The generated HTML markup.
     */
    public function innerHtml($html)
    {
        return '<'.$this->tag.$this->renderAttributes().'>'.$html.'</'.$this->tag.'>';
    }

    /**
     * Render the HTML markup for the form or element.
     *
     * @return string The rendered HTML markup.
     */
    public function render()
    {
        $result = '<'.$this->tag.$this->renderAttributes().'>'.(isset($this->attributes['innerHTML']) ? $this->attributes['innerHTML'] : '');

        foreach ($this->rows as $row) {
            if (is_string($row)) {
                // If the row is a string, append it to the result as it is.
                $result .= $row;
            } else {
                // If the row is an instance of the class, render it and append the result.
                $result .= $row->render();

                if (!empty($row->javascript)) {
                    // If the row has JavaScript scripts, add them to the form's "javascript" array.
                    foreach ($row->javascript as $script) {
                        self::$form->javascript[] = $script;
                    }
                }
            }
        }

        $result .= '</'.$this->tag.'>';

        if ($this->tag == 'form' && !empty(self::$form->javascript)) {
            // If the tag is "form" and the form has JavaScript scripts, add them to the result and reset the form instance.
            $result .= "\n".preg_replace('/^[\s\t]+/m', '', "<script>\n".implode("\n", self::$form->javascript)."\n</script>");
            self::$form = null;
        } elseif (!empty($this->javascript)) {
            // If the current instance has JavaScript scripts, add them to the result.
            $result .= "\n".preg_replace('/^[\s\t]+/m', '', "<script>\n".implode("\n", $this->javascript)."\n</script>");
        }

        return $result;
    }

    /**
     * Add a JavaScript script to the form or the current instance.
     *
     * @param string $script The JavaScript script to add.
     */
    public function script($script)
    {
        if (isset(self::$form)) {
            // If the static property "form" is set, add the script to the form's "javascript" array
            self::$form->javascript[] = $script;
        } else {
            // Otherwise, add the script to the current instance's "javascript" array
            $this->javascript[] = $script;
        }
    }

    /**
     * Render the attributes of the HTML tag as a string.
     *
     * @return string The rendered attributes.
     */
    protected function renderAttributes()
    {
        $attr = [];
        foreach ($this->attributes as $key => $value) {
            // Exclude the 'innerHTML' attribute
            if ($key != 'innerHTML') {
                if (is_int($key)) {
                    // If the key is an integer, only add the value
                    $attr[] = $value;
                } else {
                    // Otherwise, add the key-value pair as an attribute
                    $attr[] = $key.'="'.$value.'"';
                }
            }
        }
        // Concatenate the attributes with a space separator
        return count($attr) == 0 ? '' : ' '.implode(' ', $attr);
    }

    /**
     * Add a CKEditor element to the HTML.
     *
     * @param string $tag        The tag name.
     * @param array  $attributes The attributes of the element.
     *
     * @return static The added CKEditor element.
     */
    private function addCKEditor($tag, $attributes)
    {
        if (isset($attributes[$tag])) {
            $tag = $attributes[$tag];
            unset($attributes[$tag]);
        } else {
            $tag = 'textarea';
        }

        if (class_exists('Kotchasan\CKEditor')) {
            $obj = new \Kotchasan\CKEditor($tag, $attributes);
        } else {
            $obj = self::create($tag, $attributes);
        }

        $this->rows[] = $obj;

        return $obj;
    }

    /**
     * Add a groups element to the HTML.
     *
     * @param string $tag        The tag name.
     * @param array  $attributes The attributes of the element.
     *
     * @return static The added groups element.
     */
    private function addGroups($tag, $attributes)
    {
        $prop = array('class' => isset($attributes['class']) ? $attributes['class'] : 'item');

        if (isset($attributes['id'])) {
            $prop['id'] = $attributes['id'];
        }

        if (isset($attributes['label'])) {
            if (isset($attributes['for'])) {
                // Create a div element with a label for attribute
                $item = new static('div', $prop);
                $item->add('label', array(
                    'innerHTML' => $attributes['label'],
                    'for' => $attributes['for']
                ));
            } else {
                // Create a fieldset element with a title attribute
                $prop['title'] = strip_tags($attributes['label']);
                $item = self::fieldset($prop);
            }
        } else {
            // Create a div element
            $item = new static('div', $prop);
        }

        // Add the groups element to the HTML
        $this->rows[] = $item;

        $obj = $item->add('div', array('class' => 'input-'.$tag));
        $rows = [];
        $comment = [];

        if (empty($attributes['id'])) {
            $id = '';
            $name = '';
        } else {
            $id = ' id='.$attributes['id'];
            $name = ' name='.$attributes['id'].'[]';
            $comment['id'] = 'result_'.$attributes['id'];
        }

        foreach ($attributes as $key => $value) {
            if ($key == 'checkbox' || $key == 'radio') {
                foreach ($value as $v => $text) {
                    $chk = isset($attributes['value']) && in_array($v, $attributes['value']) ? ' checked' : '';
                    $rows[] = '<label>'.$text.'&nbsp;<input type='.$key.$id.$name.$chk.' value="'.$v.'"></label>';
                    $id = '';
                }
            }
        }

        if (!empty($rows)) {
            $obj->appendChild(implode('&nbsp; ', $rows));
        }

        if (isset($attributes['comment'])) {
            if (isset($attributes['commentId'])) {
                $comment['id'] = $attributes['commentId'];
            }
            $comment['class'] = 'comment';
            $comment['innerHTML'] = $value;
            $item->add('div', $comment);
        }

        return $obj;
    }

    /**
     * Add an input groups element to the HTML.
     *
     * @param array $attributes The attributes of the element.
     *
     * @return static The added input groups element.
     */
    private function addInputGroups($attributes)
    {
        if (!empty($attributes['disabled'])) {
            $attributes['disabled'] = 'disabled';
        } else {
            unset($attributes['disabled']);
        }

        if (!empty($attributes['readonly'])) {
            $attributes['readonly'] = 'readonly';
        } else {
            unset($attributes['readonly']);
        }

        $prop = array('class' => empty($attributes['itemClass']) ? 'item' : $attributes['itemClass']);

        if (isset($attributes['itemId'])) {
            $prop['id'] = $attributes['itemId'];
        }

        $obj = new static('div', $prop);
        $this->rows[] = $obj;

        if (isset($attributes['id'])) {
            $id = $attributes['id'];
        } else {
            $id = \Kotchasan\Password::uniqid();
        }

        $c = array('inputgroups');

        if (isset($attributes['labelClass'])) {
            $c[] = $attributes['labelClass'];
        }

        if (isset($attributes['label'])) {
            // Create a label element for the input groups
            $obj->add('label', array(
                'innerHTML' => $attributes['label'],
                'for' => $id
            ));
        }

        $li = '';

        if (isset($attributes['value']) && is_array($attributes['value'])) {
            if (isset($attributes['options'])) {
                // If options are provided, create li elements for each value and its corresponding option
                foreach ($attributes['value'] as $value) {
                    if (isset($attributes['options'][$value])) {
                        $li .= '<li id="'.$id.'_item_'.$value.'"><span>'.$attributes['options'][$value].'</span><button type="button">x</button><input type="hidden" name="'.$id.'[]" value="'.$value.'"></li>';
                    }
                }
            } else {
                // If options are not provided, create li elements with the values directly
                foreach ($attributes['value'] as $k => $value) {
                    $li .= '<li id="'.$id.'_item_'.$k.'"><span>'.$value.'</span><button type="button">x</button><input type="hidden" name="'.$id.'[]" value="'.$k.'"></li>';
                }
            }
        }

        foreach ($attributes as $key => $value) {
            if ($key == 'validator') {
                // If a validator is provided, create a GValidator JavaScript object
                $js = array('"'.$id.'"', '"'.$value[0].'"', $value[1]);

                if (isset($value[2])) {
                    $js[] = '"'.$value[2].'"';
                    $js[] = empty($value[3]) || $value[3] === null ? 'null' : '"'.$value[3].'"';
                    $js[] = '"'.self::$form->attributes['id'].'"';
                }

                self::$form->javascript[] = 'new GValidator('.implode(', ', $js).');';
            } elseif ($key == 'autocomplete') {
                // If autocomplete is provided, create a GAutoComplete JavaScript object
                $o = array(
                    'get' => 'get: GInputGroup.prototype.doAutocompleteGet',
                    'populate' => 'populate: GInputGroup.prototype.doAutocompletePopulate',
                    'callBack' => 'callBack: GInputGroup.prototype.doAutocompleteCallback'
                );

                foreach ($value as $k => $v) {
                    if ($k == 'url') {
                        $o['url'] = 'url: "'.$v.'"';
                    } else {
                        $o[$k] = $k.': '.$v;
                    }
                }

                self::$form->javascript[] = 'new GAutoComplete("'.$id.'",{'.implode(',', $o).'});';
            } elseif ($key == 'options') {
                $options = $value;
                $datalist = $id.'_'.\Kotchasan\Password::uniqid();
                $prop['list'] = 'list="'.$datalist.'"';
            } elseif ($key == 'comment') {
                $comment = $value;
            } elseif (!in_array($key, array('id', 'type', 'itemId', 'itemClass', 'labelClass', 'label', 'value'))) {
                $prop[$key] = $key.'="'.$value.'"';
            }
        }

        $prop['id'] = 'id="'.$id.'"';
        $prop['type'] = 'type="text"';
        $prop['class'] = 'class="inputgroup"';

        // Create an input element for the input groups
        $li .= '<li><input '.implode(' ', $prop).'>';

        if (isset($options) && is_array($options)) {
            // If options are provided, create a datalist element
            $li .= '<datalist id="'.$datalist.'">';
            foreach ($options as $k => $v) {
                $li .= '<option value="'.$k.'">'.$v.'</option>';
            }
            $li .= '</datalist>';
        }

        $li .= '</li>';

        // Create a ul element for the input groups
        $obj->add('ul', array(
            'class' => implode(' ', $c),
            'innerHTML' => $li
        ));

        if (isset($comment)) {
            // Create a div element for the comment
            $obj->add('div', array(
                'id' => 'result_'.$id,
                'class' => 'comment',
                'innerHTML' => $comment
            ));
        }

        return $obj;
    }

    /**
     * Add a menu button element to the HTML.
     *
     * @param array $attributes The attributes of the element.
     *
     * @return static The added menu button element.
     */
    private function addMenuButton($attributes)
    {
        $prop = array('class' => empty($attributes['itemClass']) ? 'item' : $attributes['itemClass']);

        if (isset($attributes['itemId'])) {
            $prop['id'] = $attributes['itemId'];
        }

        $obj = new static('div', $prop);
        $this->rows[] = $obj;

        if (isset($attributes['label'])) {
            // Create a label element for the menu button
            $obj->add('label', array(
                'innerHTML' => $attributes['label']
            ));
        }

        $div = $obj->add('div', array(
            'class' => 'g-input'
        ));

        $li = '<ul>';

        if (isset($attributes['submenus']) && is_array($attributes['submenus'])) {
            foreach ($attributes['submenus'] as $item) {
                $prop = [];
                $text = '';

                foreach ($item as $key => $value) {
                    if ($key == 'text') {
                        $text = $value;
                    } else {
                        $prop[$key] = $key.'="'.$value.'"';
                    }
                }

                $li .= '<li><a '.implode(' ', $prop).'>'.$text.'</a></li>';
            }
        }

        $li .= '</ul>';

        $prop = array(
            'class' => isset($attributes['class']) ? $attributes['class'].' menubutton' : 'menubutton',
            'tabindex' => 0
        );

        if (isset($attributes['text'])) {
            $prop['innerHTML'] = $attributes['text'].$li;
        } else {
            $prop['innerHTML'] = $li;
        }

        // Create a div element for the menu button
        $div->add('div', $prop);

        return $obj;
    }

    /**
     * Add a radio or checkbox groups element to the HTML.
     *
     * @param string $tag        The tag name ('radiogroups' or 'checkboxgroups').
     * @param array  $attributes The attributes of the element.
     *
     * @return static The added radio or checkbox groups element.
     */
    private function addRadioOrCheckbox($tag, $attributes)
    {
        $prop = array('class' => empty($attributes['itemClass']) ? 'item' : $attributes['itemClass']);

        if (!empty($attributes['itemId'])) {
            $prop['id'] = $attributes['itemId'];
        }

        $obj = new static('div', $prop);
        $this->rows[] = $obj;

        if (isset($attributes['name'])) {
            $name = $attributes['name'];
        } elseif (isset($attributes['id'])) {
            $name = $tag == 'checkboxgroups' ? $attributes['id'].'[]' : $attributes['id'];
        } else {
            $name = false;
        }

        $c = array($tag);

        if (isset($attributes['labelClass'])) {
            $c[] = $attributes['labelClass'];
        }

        if (isset($attributes['label']) && isset($attributes['id'])) {
            // Create a label element for the radio or checkbox groups
            $obj->add('label', array(
                'innerHTML' => $attributes['label'],
                'for' => $attributes['id']
            ));
        }

        if (isset($attributes['button']) && $attributes['button'] === true) {
            $c[] = 'groupsbutton';
        }

        $prop = array(
            'class' => implode(' ', $c)
        );

        if (isset($attributes['id'])) {
            $prop['id'] = $attributes['id'];
        }

        $div = $obj->add('div', $prop);

        if (!empty($attributes['multiline'])) {
            $c = array('multiline');

            if (!empty($attributes['scroll'])) {
                $c[] = 'hscroll';
            }

            // Create a div element for multiline groups
            $div = $div->add('div', array(
                'class' => implode(' ', $c)
            ));
        }

        if (!empty($attributes['options']) && is_array($attributes['options'])) {
            foreach ($attributes['options'] as $v => $label) {
                $item = array(
                    'label' => $label,
                    'value' => $v
                );

                if (isset($attributes['value'])) {
                    if (is_array($attributes['value']) && in_array($v, $attributes['value'])) {
                        $item['checked'] = $v;
                    } elseif ($v == $attributes['value']) {
                        $item['checked'] = $v;
                    }
                }

                if ($name) {
                    $item['name'] = $name;
                }

                if (isset($attributes['id'])) {
                    if (isset($attributes['button']) && $attributes['button'] === true) {
                        $item['button'] = $attributes['button'];
                        $item['class'] = (empty($attributes['class']) ? '' : $attributes['class'].' ').str_replace('groups', 'button', $tag);
                    } elseif (isset($attributes['class'])) {
                        $item['class'] = $attributes['class'];
                    }
                }

                if (isset($attributes['comment'])) {
                    $item['title'] = strip_tags($attributes['comment']);
                }

                if (!empty($attributes['disabled'])) {
                    $item['disabled'] = true;
                }

                // Add radio or checkbox element to the groups
                $div->add($tag == 'radiogroups' ? 'radio' : 'checkbox', $item);
            }
        }

        if (isset($attributes['id']) && !empty($attributes['comment'])) {
            // Create a div element for the comment
            $obj->add('div', array(
                'id' => 'result_'.$attributes['id'],
                'class' => 'comment',
                'innerHTML' => $attributes['comment']
            ));
        }

        return $obj;
    }
}
