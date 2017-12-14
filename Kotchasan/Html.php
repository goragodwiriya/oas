<?php
/**
 * @filesource Kotchasan/Html.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

/**
 * html
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Html extends \Kotchasan\KBase
{
  /**
   * ชื่อ tag
   *
   * @var string
   */
  protected $tag;
  /**
   * attrribute ของ tag
   *
   * @var array
   */
  public $attributes;
  /**
   * แอเรย์ของข้อมูลภายใน tag
   *
   * @var array
   */
  protected $rows;
  /**
   * Javascript
   *
   * @var array
   */
  protected $javascript;
  /**
   * ตัวแปรเก็บ form object
   *
   * @var \static
   */
  public static $form;

  /**
   * class Constructor
   */
  public function __construct($tag, $attributes = array())
  {
    $this->tag = strtolower($tag);
    $this->attributes = $attributes;
    $this->rows = array();
    $this->javascript = array();
  }

  /**
   * creat new Element
   *
   * @param string $tag
   * @param array $attributes
   * @return \static
   */
  public static function create($tag, $attributes = array())
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

  private function addGroups($tag, $attributes)
  {
    $prop = array('class' => isset($attributes['class']) ? $attributes['class'] : 'item');
    if (isset($attributes['id'])) {
      $prop['id'] = $attributes['id'];
    }
    if (isset($attributes['label'])) {
      if (isset($attributes['for'])) {
        $item = new static('div', $prop);
        $item->add('label', array(
          'innerHTML' => $attributes['label'],
          'for' => $attributes['for']
        ));
      } else {
        $prop['title'] = strip_tags($attributes['label']);
        $item = self::fieldset($prop);
      }
    } else {
      $item = new static('div', $prop);
    }
    $this->rows[] = $item;
    $obj = $item->add('div', array('class' => 'input-'.$tag));
    $rows = array();
    $comment = array();
    if (empty($attributes['id'])) {
      $id = '';
      $name = '';
    } else {
      $id = ' id='.$attributes['id'];
      $name = ' name='.$attributes['id'].'[]';
      $comment['id'] = 'result_'.$attributes['id'];
    }
    foreach ($attributes as $key => $value) {
      if ($key == 'comment') {
        $comment['class'] = 'comment';
        $comment['innerHTML'] = $value;
      } elseif ($key == 'checkbox' || $key == 'radio') {
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
      $item->add('div', $comment);
    }
    return $obj;
  }

  private function addRadioOrCheckbox($tag, $attributes)
  {
    $prop = array('class' => 'item');
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
    if (!empty($attributes['multiline'])) {
      $c[] = 'multiline';
    }
    if (isset($attributes['label']) && isset($attributes['id'])) {
      $obj->add('label', array(
        'innerHTML' => $attributes['label'],
        'for' => $attributes['id']
      ));
    }
    $div = $obj->add('div', array(
      'class' => implode(' ', $c)
    ));
    if (!empty($attributes['options']) && is_array($attributes['options'])) {
      foreach ($attributes['options'] as $v => $label) {
        if (isset($attributes['value'])) {
          if (is_array($attributes['value'])) {
            $checked = isset($attributes['value']) && in_array($v, $attributes['value']);
          } else {
            $checked = isset($attributes['value']) && $v == $attributes['value'];
          }
        } else {
          $checked = false;
        }
        $item = array(
          'label' => $label,
          'value' => $v,
          'checked' => $checked
        );
        if ($name) {
          $item['name'] = $name;
        }
        if (isset($attributes['id'])) {
          $item['id'] = $attributes['id'];
          $result_id = $attributes['id'];
          unset($attributes['id']);
        }
        if (isset($attributes['comment'])) {
          $item['title'] = strip_tags($attributes['comment']);
        }
        if (isset($attributes['disabled'])) {
          $item['disabled'] = true;
        }
        $div->add($tag == 'radiogroups' ? 'radio' : 'checkbox', $item);
      }
    }
    if (isset($result_id) && !empty($attributes['comment'])) {
      $obj->add('div', array(
        'id' => 'result_'.$result_id,
        'class' => 'comment',
        'innerHTML' => $attributes['comment']
      ));
    }
    return $obj;
  }

  private function addAntispam($tag, $attributes)
  {
    $antispam = new Antispam();
    $attributes['antispamid'] = $antispam->getId();
    if (isset($attributes['value']) && $attributes['value'] === true) {
      $attributes['value'] = $antispam->getValue();
    }
    $obj = self::create($tag, $attributes);
    $this->rows[] = $obj;
    $this->rows[] = self::create('hidden', array(
        'id' => $attributes['id'].'id',
        'value' => $attributes['antispamid']
    ));
    return $obj;
  }

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
   * แทรก tag ลงใน element เหมือนการใช้งาน innerHTML
   *
   * @param string $tag
   * @param array $attributes
   * @return \static
   */
  public function add($tag, $attributes = array())
  {
    $tag = strtolower($tag);
    if ($tag == 'groups' || $tag == 'groups-table') {
      $obj = $this->addGroups($tag, $attributes);
    } elseif ($tag == 'radiogroups' || $tag == 'checkboxgroups') {
      $obj = self::addRadioOrCheckbox($tag, $attributes);
    } elseif ($tag == 'antispam') {
      $obj = self::addAntispam($tag, $attributes);
    } elseif ($tag == 'ckeditor') {
      $obj = self::addCKEditor($tag, $attributes);
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
   * create Fieldset element
   *
   * @param array $attributes
   * @return \static
   */
  public static function fieldset($attributes = array())
  {
    $prop = array();
    $span = array();
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
   * create Form element
   *
   * @param array $attributes
   * @return \static
   */
  public static function form($attributes = array())
  {
    $ajax = false;
    $prop = array('method' => 'post');
    $gform = true;
    $token = false;
    foreach ($attributes as $key => $value) {
      if ($key === 'ajax' || $key === 'action' || $key === 'onsubmit' || $key === 'onbeforesubmit' ||
        $key === 'elements' || $key === 'script' || $key === 'gform' || $key === 'token') {
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
      self::$form->rows[] = '<input type="hidden" name="token" id="token" value="'.self::$request->createToken().'">';
    }
    if (isset($script)) {
      self::$form->javascript[] = $script;
    }
    return self::$form;
  }

  /**
   * create Table
   *
   * @param array $attributes
   * @return HtmlTable
   */
  public static function table($attributes = array())
  {
    return HtmlTable::create($attributes);
  }

  /**
   * กำหนด Javascript
   *
   * @param string $script
   */
  public function script($script)
  {
    if (isset(self::$form)) {
      self::$form->javascript[] = $script;
    } else {
      $this->javascript[] = $script;
    }
  }

  /**
   * สร้าง element และแทรก HTML ลงใน tag ให้ผลลัพท์เป็น string เลย
   *
   * @param string $html
   * @return string
   */
  public function innerHtml($html)
  {
    return '<'.$this->tag.$this->renderAttributes().'>'.$html.'</'.$this->tag.'>';
  }

  /**
   * แทรก HTML ลงใน element ที่ตำแหน่งท้ายสุด
   *
   * @param string $html
   */
  public function appendChild($html)
  {
    $this->rows[] = $html;
  }

  /**
   * สร้างโค้ด HTML
   *
   * @return string
   */
  public function render()
  {
    $result = '<'.$this->tag.$this->renderAttributes().'>'.(isset($this->attributes['innerHTML']) ? $this->attributes['innerHTML'] : '');
    foreach ($this->rows as $row) {
      if (is_string($row)) {
        $result .= $row;
      } else {
        $result .= $row->render();
        if (!empty($row->javascript)) {
          foreach ($row->javascript as $script) {
            self::$form->javascript[] = $script;
          }
        }
      }
    }
    $result .= '</'.$this->tag.'>';
    if ($this->tag == 'form' && !empty(self::$form->javascript)) {
      $result .= "\n".preg_replace('/^[\s\t]+/m', '', "<script>\n".implode("\n", self::$form->javascript)."\n</script>");
      self::$form = null;
    } elseif (!empty($this->javascript)) {
      $result .= "\n".preg_replace('/^[\s\t]+/m', '', "<script>\n".implode("\n", $this->javascript)."\n</script>");
    }
    return $result;
  }

  /**
   * สร้าง Attributes ของ tag
   *
   * @return string
   */
  protected function renderAttributes()
  {
    $attr = array();
    foreach ($this->attributes as $key => $value) {
      if ($key != 'innerHTML') {
        if (is_int($key)) {
          $attr[] = $value;
        } else {
          $attr[] = $key.'="'.$value.'"';
        }
      }
    }
    return sizeof($attr) == 0 ? '' : ' '.implode(' ', $attr);
  }
}