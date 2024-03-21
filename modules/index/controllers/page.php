<?php
/**
 * @filesource modules/index/controllers/page.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Page;

use Kotchasan\Http\Request;
use Kotchasan\Language;
use Kotchasan\Template;

/**
 * page=xxx
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * หน้าเว็บไซต์เปล่าๆ
     *
     * @param Request $request
     *
     * @return string
     */
    public function render(Request $request)
    {
        // หน้าที่เลือก
        $src = $request->request('src')->filter('a-z');
        $pages = Language::get('PAGES', []);
        if (isset($pages[$src])) {
            if (file_exists(ROOT_PATH.DATA_FOLDER.'pages/'.$src.'_'.LANGUAGE.'.html')) {
                // content
                $content = file_get_contents(ROOT_PATH.DATA_FOLDER.'pages/'.$src.'_'.LANGUAGE.'.html');
                // title, menu, bodyClass
                if (preg_match('/<h1[^>]{0,}>(.*)<\/h1>/', $content, $match)) {
                    $this->title = strip_tags($match[1]);
                } else {
                    $this->title = $pages[$src];
                }
                $this->menu = $src;
                $this->bodyClass = 'page';
                // page.html
                $template = Template::create('', '', 'page');
                $template->add(array(
                    '/{CONTENT}/' => $content
                ));
                // คืนค่า HTML
                return $template->render();
            }
        }
        // 404
        return \Index\Error\Controller::execute($this, $request->getUri());
    }
}
