<?php

/**
 * 把外部链接转换为DDSB短链接
 *
 * @package DDSBLinks
 * @author BBleae & Nyarime
 * @version 1.1.0 b2
 * @link https://dd.sb/
 */
class DDSBLinks_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return String
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        $db = Typecho_Db::get();
        $ddsblinks = $db->getPrefix() . 'ddsblinks';
        $adapter = $db->getAdapterName();
        if ("Pdo_SQLite" === $adapter || "SQLite" === $adapter) {
            $db->query(" CREATE TABLE IF NOT EXISTS " . $ddsblinks . "(
			id INTEGER PRIMARY KEY,
			longurl TEXT,
            shorturl TEXT)");
        }
        if ("Pdo_Mysql" === $adapter || "Mysql" === $adapter) {
            $dbConfig = Typecho_Db::get()->getConfig()[0];
            $charset = $dbConfig->charset;
            $db->query("CREATE TABLE IF NOT EXISTS " . $ddsblinks . "(
				`id` int(8) NOT NULL AUTO_INCREMENT,
				`longurl` TEXT NOT NULL,
				`shorturl` varchar(64) NOT NULL,
				PRIMARY KEY (`id`)
				) DEFAULT CHARSET=${charset} AUTO_INCREMENT=1");
        }
        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = array('DDSBLinks_Plugin', 'replace');
        Typecho_Plugin::factory('Widget_Abstract_Contents')->excerptEx = array('DDSBLinks_Plugin', 'replace');
        Typecho_Plugin::factory('Widget_Abstract_Contents')->filter = array('DDSBLinks_Plugin', 'replace');
        Typecho_Plugin::factory('Widget_Abstract_Comments')->filter = array('DDSBLinks_Plugin', 'replace');
        Typecho_Plugin::factory('Widget_Archive')->singleHandle = array('DDSBLinks_Plugin', 'replace');
        return ('数据表 ' . $ddsblinks . ' 创建成功，插件已经成功激活！');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return String
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        $config = Typecho_Widget::widget('Widget_Options')->plugin('DDSBLinks');
        $db = Typecho_Db::get();
        $db->query("DROP TABLE `{$db->getPrefix()}ddsblinks`", Typecho_Db::WRITE);
        return ('DDSBLinks已被禁用，其表（_ddsblinks）已被删除！');
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $radio = new Typecho_Widget_Helper_Form_Element_Radio('convert', array('1' => _t('开启'), '0' => _t('关闭')), '1', _t('外链转DD.SB链接'), _t('开启后会帮你把外链转换成DD.SB链接'));
        $form->addInput($radio);
        $radio = new Typecho_Widget_Helper_Form_Element_Radio('convertCommentLink', array('1' => _t('开启'), '0' => _t('关闭')), '1', _t('转换评论者链接'), _t('开启后会帮你把评论者链接转换成DD.SB链接'));
        $form->addInput($radio);

        $radio = new Typecho_Widget_Helper_Form_Element_Radio('target', array('1' => _t('开启'), '0' => _t('关闭')), '1', _t('新窗口打开文章中的链接'), _t('开启后给文章中的链接新增 target 属性'));
        $form->addInput($radio);

        $radio = new Typecho_Widget_Helper_Form_Element_Radio('authorPermalinkTarget', array('1' => _t('开启'), '0' => _t('关闭')), '0', _t('新窗口打开评论者链接'), _t('开启后给评论者链接新增 target 属性。（URL 中 target 属性，开启可能会引起主题异常）'));
        $form->addInput($radio);

        $textarea = new Typecho_Widget_Helper_Form_Element_Textarea('convertCustomField', null, null, _t('需要处理的自定义字段'), _t('在这里设置需要处理的自定义字段，一行一个（实验性功能）'));
        $form->addInput($textarea);
        $radio = new Typecho_Widget_Helper_Form_Element_Radio('nullReferer', array('1' => _t('开启'), '0' => _t('关闭')), '1', _t('允许空 referer'), _t('开启后会允许空 referer'));
        $form->addInput($radio);
        $refererList = new Typecho_Widget_Helper_Form_Element_Textarea('refererList', null, null, _t('referer 白名单'), _t('在这里设置 referer 白名单，一行一个'));
        $form->addInput($refererList);
        $nonConvertList = new Typecho_Widget_Helper_Form_Element_Textarea('nonConvertList', null, _t("itxe.net" . PHP_EOL . "idc.moe" . PHP_EOL . "dd.sb" . PHP_EOL . "idc.cy"), _t('外链转换白名单'), _t('在这里设置外链转换白名单（评论者链接不生效）'));
        $form->addInput($nonConvertList);
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 外链转DD.SB链接
     *
     * @access public
     * @param $content
     * @param $class
     * @return $content
     */
    public static function replace($text, $widget, $lastResult)
    {
        $text = empty($lastResult) ? $text : $lastResult;
        $pluginOption = Typecho_Widget::widget('Widget_Options')->Plugin('DDSBLinks'); // 插件选项
        $siteUrl = Helper::options()->siteUrl;
        $target = ($pluginOption->target) ? ' target="_blank" ' : ''; // 新窗口打开
        if ($pluginOption->convert == 1) {
            if (!is_string($text) && $text instanceof Widget_Archive) {
                // 自定义字段处理
                $fieldsList = self::textareaToArr($pluginOption->convertCustomField);
                if ($fieldsList) {
                    foreach ($fieldsList as $field) {
                        if (isset($text->fields[$field])) {
                            @preg_match_all('/<a(.*?)href="(.*?)"(.*?)>/', $text->fields[$field], $matches);
                            if ($matches) {
                                foreach ($matches[2] as $link) {
                                    $text->fields[$field] = str_replace("href=\"$link\"", "href=\"" . self::convertLink($link) . "\"", $text->fields[$field]);
                                }
                            }
                        }
                    }
                }
            }
            if (($widget instanceof Widget_Archive) || ($widget instanceof Widget_Abstract_Comments)) {
                $fields = unserialize($widget->fields);
                if (is_array($fields) && array_key_exists("noshort", $fields)) {
                    return $text;
                }

                // 文章内容和评论内容处理
                @preg_match_all('/<a(.*?)href="(.*?)"(.*?)>/', $text, $matches);
                if ($matches) {
                    foreach ($matches[2] as $link) {
                        $text = str_replace("href=\"$link\"", "href=\"" . self::convertLink($link) . "\"" . $target, $text);
                    }
                }
            }
            if ($pluginOption->convertCommentLink == 1 && $widget instanceof Widget_Abstract_Comments) {
                // 评论者链接处理
                $url = $text['url'];
                if (strpos($url, '://') !== false && strpos($url, rtrim($siteUrl, '/')) === false) {
                    $text['url'] = self::convertLink($url, false);
                    if ($pluginOption->authorPermalinkTarget) {
                        $text['url'] = $text['url'] . '" target="_blank';
                    }
                }
            }
        }
        return $text;
    }

    /**
     * 转换链接形式
     *
     * @access public
     * @param $link
     * @return $string
     */
    public static function convertLink($link, $check = true)
    {
        $rewrite = (Helper::options()->rewrite) ? '' : 'index.php/'; // 伪静态处理
        $pluginOption = Typecho_Widget::widget('Widget_Options')->Plugin('DDSBLinks'); // 插件选项
        $linkBase = ltrim(rtrim(Typecho_Router::get('go')['url'], '/'), '/'); // 防止链接形式修改后不能用
        $siteUrl = Helper::options()->siteUrl;
        $target = ($pluginOption->target) ? ' target="_blank" ' : ''; // 新窗口打开
        $nonConvertList = self::textareaToArr($pluginOption->nonConvertList); // 不转换列表
        if ($check) {
            if (strpos($link, '://') !== false && strpos($link, rtrim($siteUrl, '/')) !== false) {
                return $link;
            }
            //本站链接不处理
            if (self::checkDomain($link, $nonConvertList)) {
                return $link;
            }
            // 不转换列表中的不处理
            if (preg_match('/\.(jpg|jepg|png|ico|bmp|gif|tiff)/i', $link)) {
                return $link;
            }
            // 图片不处理
        }
        return self::DdsbUrl($link);
    }

    /**
     * 检查域名是否在数组中存在
     *
     * @access public
     * @param $url $arr
     * @param $class
     * @return boolean
     */
    public static function checkDomain($url, $arr)
    {
        if ($arr === null) {
            return false;
        }

        if (count($arr) === 0) {
            return false;
        }

        foreach ($arr as $a) {
            if (strpos($url, $a) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 一行一个文本框转数组
     *
     * @access public
     * @param $textarea
     * @param $class
     * @return $arr
     */
    public static function textareaToArr($textarea)
    {
        $str = str_replace(array("\r\n", "\r", "\n"), "|", $textarea);
        if ($str == "") {
            return null;
        }

        return explode("|", $str);
    }
    /**
     * Base64 解码
     *
     * @param string $str
     * @return string
     * @date 2020-05-01
     */
    public static function urlSafeB64Decode($str)
    {
        $data = str_replace(array('-', '_'), array('+', '/'), $str);
        $mod = strlen($data) % 4;
        if ($mod) {
            $data .= substr('====', $mod);
        }
        return base64_decode($data);
    }
    /**
     * Base64 编码
     *
     * @param string $str
     * @return string
     * @date 2020-05-01
     */
    public static function urlSafeB64Encode($str)
    {
        $data = base64_encode($str);
        $data = str_replace(array('+', '/', '='), array('-', '_', ''), $data);
        return $data;
    }
    public static function DdsbUrl($url){
        $db = Typecho_Db::get();
        $cache = $db->fetchObject($db->select()
                    ->from('table.ddsblinks')
                    ->where('longurl = ?', $url));
        if ($cache->longurl == $url) {
            return $cache->shorturl;
        } else {
            $requestUrl = "https://dd.sb/api.php?url=$url";
            $res = file_get_contents($requestUrl);
            $result = json_decode(self::checkBOM($res), TRUE);
            if ($result['error'] != 0) {
                return $link;
            } else {
                self::addCache($url, $result['shorturl']);
                return $result['shorturl'];
            }
        }
    }

    /**
     * 添加新的链接缓存
     *
     */
    public static function addCache($longurl, $shorturl)
    {
        if (!$shorturl) {
            return;
        }
        $db = Typecho_Db::get();
        $db->query($db->insert('table.ddsblinks')->rows(array(
            'longurl' => $longurl,
            'shorturl' => $shorturl,
        )));
    }

    public static function checkBOM($contents) {
        $charset[1] = substr($contents, 0, 1);
        $charset[2] = substr($contents, 1, 1);
        $charset[3] = substr($contents, 2, 1);
        if (ord($charset[1]) == 239 && ord($charset[2]) == 187 && ord($charset[3]) == 191) {
            return substr($contents, 3);
        } else {
            return $contents;
        }
    }
}
