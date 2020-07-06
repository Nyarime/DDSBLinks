<?php

/**
 * DDSBLinks Plugin
 *
 * @copyright  Copyright (c) 2011 DEFE (http://defe.me)
 * @license    GNU General Public License 2.0
 *
 */
class DDSBLinks_Action extends Typecho_Widget implements Widget_Interface_Do
{
    private $db;
    private $options;
    public function __construct($request, $response, $params = null)
    {
        parent::__construct($request, $response, $params);
        $this->db = Typecho_Db::get();
        $this->options = self::getOptions();
        $this->options->setDefault('logoUrl=' . Helper::options()->pluginUrl . '/DDSBLinks/logo.png&siteCreatedYear=' . Helper::options()->plugin('DDSBLinks')->siteCreatedYear . '&currentYear=' . date('Y'));
    }

    /**
     * 链接重定向
     *
     */
    public function shortlink()
    {
        $requestString = $this->request->key;
        $siteUrl = preg_replace("/https?:\/\//", "", Typecho_Widget::widget('Widget_Options')->siteUrl);
        $pOption = Typecho_Widget::widget('Widget_Options')->Plugin('DDSBLinks'); // 插件选项
        $referer = $this->request->getReferer();
        $template = $pOption->goTemplate == 'NULL' ? null : $pOption->goTemplate;
        // 允许空 referer
        if (empty($referer) && $pOption->nullReferer === "1") {
            $referer = $siteUrl;
        }

        $refererList = DDSBLinks_Plugin::textareaToArr($pOption->refererList); // 允许的referer列表
        $target = $this->getTarget($requestString);
        // 设置nofollow属性
        $this->response->setHeader('X-Robots-Tag', 'noindex, nofollow');
        if ($target) {
            // 自定义短链
            // 增加统计
            $count = $this->db->fetchObject($this->db->select('count')
                    ->from('table.ddsblinks')
                    ->where('key = ?', $requestString))->count;
            $count = $count + 1;
            $this->db->query($this->db->update('table.ddsblinks')
                    ->rows(array('count' => $count))
                    ->where('key = ?', $requestString));
        } else if ($requestString === DDSBLinks_Plugin::urlSafeB64Encode(DDSBLinks_Plugin::urlSafeB64Decode($requestString))) {
            // 自动转换链接处理
            $target = DDSBLinks_Plugin::urlSafeB64Decode($requestString);
            $allow_redirect = false; // 默认不允许跳转
            // 检查 referer
            $allow_redirect = DDSBLinks_Plugin::checkDomain($referer, $refererList);
            if (strpos($referer, $siteUrl) !== false) {
                $allow_redirect = true;
            }
            if (!$allow_redirect) {
                // referer 非法跳转到首页
                $this->response->redirect($siteUrl, 301);
                exit();
            }
        } else {
            throw new Typecho_Widget_Exception(_t('您访问的网页不存在'), 404);
        }
        $this->response->redirect(htmlspecialchars_decode($target), 301);
    }
    /**
     * 获取目标链接
     *
     * @param string $key
     * @return void
     */
    public function getTarget($key)
    {
        $target = $this->db->fetchRow($this->db->select('target')
                ->from('table.ddsblinks')
                ->where(' key = ?', $key));
        if (isset($target['target'])) {
            return $target['target'];
        } else {
            return false;
        }
    }

    /**
     * 重设自定义链接
     */
    public function resetLink()
    {
        Typecho_Response::throwJson('success');
    }

    public function action()
    {
        $this->widget('Widget_User')->pass('administrator');
        $this->response->goBack();
    }

    public function getOptions()
    {
        $values = $this->db->fetchAll($this->db->select('name', 'value')->from('table.options')->where('user = 0'));
        $options = array();
        foreach ($values as $value) {
            if (strpos($value['name'], "plugin:") === 0) {
                continue;
            }

            $options[$value['name']] = $value['value'];
        }
        /** 主题变量重载 */
        if (!empty($options['theme:' . $options['theme']])) {
            $themeOptions = null;

            /** 解析变量 */
            if ($themeOptions = unserialize($options['theme:' . $options['theme']])) {
                /** 覆盖变量 */
                $options = array_merge($options, $themeOptions);
            }
        }
        $options['rootUrl'] = defined('__TYPECHO_ROOT_URL__') ? __TYPECHO_ROOT_URL__ : $this->request->getRequestRoot();
        if (defined('__TYPECHO_ADMIN__')) {
            /** 识别在admin目录中的情况 */
            $adminDir = '/' . trim(defined('__TYPECHO_ADMIN_DIR__') ? __TYPECHO_ADMIN_DIR__ : '/admin/', '/');
            $options['rootUrl'] = substr($options['rootUrl'], 0, -strlen($adminDir));
        }
        if (defined('__TYPECHO_SITE_URL__')) {
            $options['siteUrl'] = __TYPECHO_SITE_URL__;
        } else if (defined('__TYPECHO_DYNAMIC_SITE_URL__') && __TYPECHO_DYNAMIC_SITE_URL__) {
            $options['siteUrl'] = $options['rootUrl'];
        }
        $options['originalSiteUrl'] = $options['siteUrl'];
        $options['siteUrl'] = Typecho_Common::url(null, $options['siteUrl']);

        return Typecho_Config::factory($options);
    }
    /**
     * 替换回调
     *
     * @param Array $matches
     * @return String
     * @date 2020-05-01
     */
    private function __relpaceCallback($matches)
    {
        return $this->options->{$matches[1]};
    }
}
