<?php
namespace Kotchasan;

/**
 * Kotchasan Form Class
 *
 * Static schema-driven form renderer for the current Now.js frontend.
 * Builds declarative <form> markup (data-form/data-attr/data-validate/...)
 * from a field-schema array — the same shape used by Gcms\Runtime\FormSchema
 * on the client side. FormManager on the frontend binds values, validates
 * (native HTML5 — see FRAMEWORK_GUIDE.md) and submits; nothing here emits
 * JavaScript.
 *
 * @package Kotchasan
 */
class Form extends \Kotchasan\KBase
{
    /**
     * ไอคอนเริ่มต้นต่อชนิดฟิลด์ (form-control ใช้ class ไอคอนเป็นสัญลักษณ์นำ)
     *
     * @var array
     */
    protected static $schemaIcons = [
        'text' => 'icon-edit', 'textarea' => 'icon-comment', 'number' => 'icon-number',
        'date' => 'icon-calendar', 'datetime' => 'icon-calendar', 'select' => 'icon-menus',
        'email' => 'icon-email', 'url' => 'icon-link', 'tel' => 'icon-phone', 'file' => 'icon-upload'
    ];

    /**
     * สร้าง <form> ตามมาตรฐาน Now.js จาก schema (โครงเดียวกับ FormSchema::build)
     *
     * @param array $schema {fields: [...], metadata?: {...}} หรือ array ของ fields ตรงๆ
     * @param array $opt {name, action, load, legend, submit} — data-form/action/data-load-api ฯลฯ
     *
     * @return string HTML ของฟอร์มทั้งชุด
     */
    public static function createForm(array $schema, array $opt = [])
    {
        $fields = isset($schema['fields']) && is_array($schema['fields']) ? $schema['fields'] : $schema;
        $name = $opt['name'] ?? ($schema['metadata']['id'] ?? 'form');
        $action = $opt['action'] ?? '';
        $load = $opt['load'] ?? '';
        $legend = $opt['legend'] ?? 'Details';
        $submit = $opt['submit'] ?? 'Save';

        // แยก hidden (ระดับ form) / fieldset / child (อ้างด้วย id)
        $byId = [];
        foreach ($fields as $f) {
            if (isset($f['id'])) {
                $byId[$f['id']] = $f;
            }
        }
        $hidden = '';
        $body = '';
        $consumed = [];
        foreach ($fields as $f) {
            $type = $f['type'] ?? 'text';
            if ($type === 'hidden') {
                $hidden .= "  ".self::schemaHidden($f)."\n";
            } elseif ($type === 'fieldset') {
                $legendText = self::esc($f['legend'] ?? $legend);
                $body .= "  <fieldset>\n    <legend data-i18n>".$legendText."</legend>\n";
                foreach ((array) ($f['fields'] ?? []) as $cid) {
                    if (isset($byId[$cid])) {
                        $body .= self::renderField($byId[$cid]);
                        $consumed[$cid] = true;
                    }
                }
                $body .= "  </fieldset>\n";
            }
        }
        // child ที่ไม่มี fieldset อ้างถึง → ห่อใน fieldset เดียว
        $loose = '';
        foreach ($fields as $f) {
            $type = $f['type'] ?? 'text';
            if ($type === 'hidden' || $type === 'fieldset') {
                continue;
            }
            if (empty($consumed[$f['id'] ?? ''])) {
                $loose .= self::renderField($f);
            }
        }
        if ($loose !== '') {
            $body .= "  <fieldset>\n    <legend data-i18n>".self::esc($legend)."</legend>\n".$loose."  </fieldset>\n";
        }

        $attrs = 'data-form="'.self::esc($name).'"'
            .' data-validate="true" data-reset="false"'
            .($action !== '' ? ' action="'.self::esc($action).'" method="post" data-ajax-submit="true"' : '')
            .' data-load-query-params="true"'
            .($load !== '' ? ' data-load-api="'.self::esc($load).'"' : '')
            .' autocomplete="off"';

        return "<form ".$attrs.">\n".$hidden.$body
        ."  <fieldset class=\"form-actions\">\n"
        ."    <button type=\"submit\" class=\"btn btn-primary icon-save\" data-i18n>".self::esc($submit)."</button>\n"
            ."  </fieldset>\n</form>\n";
    }

    /**
     * สร้างฟิลด์เดียวตามมาตรฐาน Now.js: <div><label><span.form-control>{control}</span></div>
     * ผูกค่าผ่าน data-attr="value:{name}" (FormManager โหลด/บันทึกให้เอง)
     * checkbox/radio ผูกด้วย data-attr="checked:{name}" ตาม convention จริงของ FormManager
     * (ดู app/templates/settings/general.html) — ไม่ใช่ "value:" แบบฟิลด์อื่น
     *
     * @param array $f field config {type, name, id, label, required, readonly, options, ...}
     *
     * @return string
     */
    public static function renderField(array $f)
    {
        $type = $f['type'] ?? 'text';
        $name = $f['name'] ?? '';
        $id = $f['id'] ?? ('f_'.$name);
        $label = self::esc($f['label'] ?? $name);
        $icon = self::$schemaIcons[$type] ?? 'icon-edit';
        $req = !empty($f['required']) ? ' required' : '';
        $ro = !empty($f['readonly']) ? ' readonly' : '';

        // checkbox/radio ใช้ layout เรียบ <div><input><label> ไม่ใช้ form-control span
        // (ตรงกับทุกฟอร์มที่เขียนมือในระบบ — ดู app/templates/settings/general.html,
        // app/templates/demo/settings.html)
        if ($type === 'checkbox') {
            return "    <div>\n"
            ."      <input type=\"checkbox\" class=\"switch\" id=\"".self::esc($id)."\" name=\"".self::esc($name)."\""
                ." value=\"1\" data-attr=\"checked:".self::esc($name)."\"".$req.">\n"
            ."      <label for=\"".self::esc($id)."\" data-i18n>".$label."</label>\n"
            ."    </div>\n";
        }

        if ($type === 'radio') {
            $items = '';
            foreach ((array) ($f['options'] ?? []) as $k => $v) {
                $optId = self::esc($id.'_'.$k);
                $items .= "      <label><input type=\"radio\" id=\"".$optId."\" name=\"".self::esc($name)."\""
                    ." value=\"".self::esc((string) $k)."\" data-attr=\"checked:".self::esc($name)."\"".$req.">"
                    ." <span data-i18n>".self::esc((string) $v)."</span></label>\n";
            }

            return "    <div>\n"
            ."      <label data-i18n>".$label."</label>\n"
                .$items
            ."    </div>\n";
        }

        $common = 'id="'.self::esc($id).'" name="'.self::esc($name).'" data-attr="value:'.self::esc($name).'"';

        if ($type === 'select') {
            if (!empty($f['optionsKey'])) {
                // options โหลดตอน runtime (framework ผ่าน data-options-key) — ไม่ bake
                // ใช้กับ category/FK ที่ค่าตัวเลือกเปลี่ยนได้ ไม่ตายตัวในไฟล์
                $control = '<select '.$common.' data-options-key="'.self::esc($f['optionsKey']).'"'.$req.'></select>';
            } else {
                $opts = '';
                if (isset($f['placeholder'])) {
                    $opts .= '<option value="">'.self::esc($f['placeholder']).'</option>';
                }
                foreach ((array) ($f['options'] ?? []) as $k => $v) {
                    $opts .= '<option value="'.self::esc((string) $k).'">'.self::esc((string) $v).'</option>';
                }
                $control = '<select '.$common.$req.'>'.$opts.'</select>';
            }
        } elseif ($type === 'textarea') {
            $rows = (int) ($f['rows'] ?? 3);
            $control = '<textarea '.$common.' rows="'.$rows.'"'.$req.$ro.'></textarea>';
        } elseif ($type === 'file') {
            // input ประเภทไฟล์ set ค่าด้วยสคริปต์ไม่ได้ (บล็อกโดย browser) จึงไม่มี
            // data-attr ผูกค่า — เก็บ/แสดงไฟล์เดิมผ่าน FileElementFactory เอง
            // (data-element="file", ดู Now/js/FileElementFactory.js)
            $extra = '';
            if (!empty($f['accept'])) {
                $extra .= ' accept="'.self::esc((string) $f['accept']).'"';
            }
            if (!empty($f['preview'])) {
                $extra .= ' data-preview="true"';
            }
            $control = '<input type="file" id="'.self::esc($id).'" name="'.self::esc($name).'"'
                .' data-element="file"'.$extra.$req.'>';
        } else {
            $inputType = in_array($type, ['number', 'date', 'datetime', 'email', 'url', 'tel'], true)
                ? ($type === 'datetime' ? 'datetime-local' : $type)
                : 'text';
            $extra = '';
            if (isset($f['maxLength'])) {
                $extra .= ' maxlength="'.(int) $f['maxLength'].'"';
            }
            if (isset($f['step'])) {
                $extra .= ' step="'.self::esc((string) $f['step']).'"';
            }
            $control = '<input type="'.$inputType.'" '.$common.$extra.$req.$ro.'>';
        }

        return "    <div>\n"
        ."      <label for=\"".self::esc($id)."\" data-i18n>".$label."</label>\n"
            ."      <span class=\"form-control ".$icon."\">".$control."</span>\n"
            ."    </div>\n";
    }

    /**
     * hidden input ระดับ form
     *
     * @param array $f
     *
     * @return string
     */
    protected static function schemaHidden(array $f)
    {
        $name = self::esc($f['name'] ?? '');
        $id = self::esc($f['id'] ?? ('f_'.($f['name'] ?? '')));

        return '<input type="hidden" id="'.$id.'" name="'.$name.'" data-attr="value:'.$name.'">';
    }

    /**
     * escape ค่าเข้า HTML attribute/text
     *
     * @param string $v
     *
     * @return string
     */
    protected static function esc($v)
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}
