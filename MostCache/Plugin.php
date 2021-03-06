<?php
/**
 * MYSQL/SAE memcache缓存插件<br />使用Mysql或SAE的memcache缓存页面减少数据库查询次数
 * 
 * @package MostCache
 * @author skylzl
 * @version 1.0.0
 * @link http://www.phoneshuo.com
 */
class MostCache_Plugin implements Typecho_Plugin_Interface
{ 
    private static $pluginName = 'MostCache';
    private static $tableName = 'most_cache';
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     * 
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        $meg = MostCache_Plugin::install();
        Typecho_Plugin::factory('index.php')->begin = array(self::$pluginName . '_Plugin', 'getCache');
        Typecho_Plugin::factory('index.php')->end = array(self::$pluginName . '_Plugin', 'setCache');       
	Typecho_Plugin::factory('Widget_Feedback')->finishComment = array(self::$pluginName . '_Plugin', 'delCache');
	Typecho_Plugin::factory('Widget_Contents_Page_Edit')->write = array(self::$pluginName . '_Plugin', 'delCache');         
	Typecho_Plugin::factory('Widget_Contents_Post_Edit')->write = array(self::$pluginName . '_Plugin', 'delCache');
        Helper::addAction('mostcache', 'MostCache_Action');
        Helper::addPanel(1, 'MostCache/panel.php', 'MostCache', 'MostCache缓存管理',   'administrator');
        return _t($meg.'。请进行<a href="options-plugin.php?config='.self::$pluginName.'">初始化设置</a>');
    }
    
    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * 
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate(){
        $installDb = Typecho_Db::get();     
        $installDb->query("DROP TABLE IF EXISTS " . $installDb->getPrefix() . self::$tableName);
        Helper::removeAction('mostcache');
        Helper::removePanel(1, 'MostCache/panel.php');
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
        $cacheMode = new Typecho_Widget_Helper_Form_Element_Radio(
          'cacheMode', array('Mysql' => 'Mysql', 'SAE' => 'SAE'), 'Mysql',
          '缓存模式', '一般选择Mysql,如果是使用新浪SAE的童鞋则选择SAE.两种模式使用不同的缓存储存介质,Mysql模式使用mysql数据储存,SAE模式使用memcache数据储存。<a href="http://www.woyoudian.com/PHP/typecho-mostcache-plugin.html">插件更新地址</a>');
        $form->addInput($cacheMode);

        $selectArr = array(
			'\/$|\/page\/\d'=>'首页','\/category'=>'分类','\/archive'=>'内容页','\/.*?\.(htm|html)$'=>'独立页面'          
        );

        $cacheType = new Typecho_Widget_Helper_Form_Element_Checkbox(
          'cacheType', $selectArr, array(),
          '缓存项目', 'Typecho 需要缓存的地方不多,一般为列表页、评论列表等列表性质的数据查询。缓存管理中可以对缓存规则进行自定义设置。');
        $form->addInput($cacheType);
		
        $cacheTime = new Typecho_Widget_Helper_Form_Element_Text('cacheTime', NULL,1, _t('缓存时间'),'以天(24H)为时间单位,最小为1,最大不限制');
        $form->addInput($cacheTime);

        $cacheCounter = new Typecho_Widget_Helper_Form_Element_Radio(
          'cacheCounter', array('0' => '否', '1' => '是'), 0,
          '访问统计', '如果缓存项目中选择了“内容页”且同时安装了willin kan的《Typecho 版 PostViews》,可以选“是”,开启缓存模式下的统计功能,否则会无法进行浏览量统计');
        $form->addInput($cacheCounter);

        $cacheTester = new Typecho_Widget_Helper_Form_Element_Radio(
          'cacheTester', array('0' => '关闭', '1' => '开启'), 0,
          '缓存检测', '缓存插件有木有生效,相信您一定很想知道。开启本项将会在页面的最下方显示缓存信息。注意一般情况下应该关闭本项。仅作测试之用。');
        $form->addInput($cacheTester);
    }
     
    public static function getCache(){
    	global $noCache;
        $installDb = Typecho_Db::get();
    	$config  = Helper::options()->plugin(self::$pluginName);
    	$request = new Typecho_Request;
    	$requestHash = $request->getPathinfo();
		#尝试获取缓存		
		if($config->cacheType){
                    $s = implode('|',$config->cacheType);
                    $preg = '/^('.$s.')/';
                    if(preg_match($preg,$requestHash)){
                    $cache = self::get($requestHash);
                            if(!$cache){
                                    $noCache = true;
                                    return;
                            }else{
                                    #增加访问统计,解决缓存造成文章无法统计
                                    if($config->cacheCounter and preg_match_all('/^\/archives\/(\d+)/',$requestHash,$cid_)){
                                            $cid = intval($cid_[1][0]);
                                            if($cid){
                                                    $installDb->query("UPDATE ".$installDb->getPrefix()."contents SET views=views+1 WHERE cid='$cid'");
                                            }
                                    }
                                    echo $cache;
                                    if($config->cacheTester) echo '<small style="font-size:10px;color:#bbb;">读取缓存内容::'.round((strlen($cache)/1024),2).'K</small>';
                                    exit;	
                            }
                    }
                }
    }
    public static function setCache(){
    	global $noCache;
    	
    	if(!$noCache) return;
    	
    	$request = new Typecho_Request;
    	$requestHash = $request->getPathinfo();
		
		#尝试存入缓存


		$cache = ob_get_contents();
			
		self::set($requestHash,$cache);
		$config  = Helper::options()->plugin(self::$pluginName);
		if($config->cacheTester) echo '<small style="font-size:10px;color:#bbb;">生成缓存内容:'.round((strlen($cache)/1024),2).'K 将会缓存:'.$config->cacheTime.'天 期间如有新文章发布、新评论产生将自动刷新缓存</small>';
		return;
		
    }
	/**
	 * @清除指定标帜缓存
	 */
	public static function delCache($param,$param2){
		if(is_object($param) and intval($param->cid)>0){#评论更新
			self::del($param->pathinfo);			
		}elseif(is_array($param) and $param['text']){#发布文章更新
                        $config  = Helper::options()->plugin(self::$pluginName);
                        $s = implode('|',$config->cacheType);
                        $del = array($param2->pathinfo);         
                        if(strstr($s, 'page',TRUE)){
                            array_push($del, '/','/page/*');
                        }
                        if(strstr($s, 'category',TRUE)){
                            foreach ($param2->categories as $key => $value) {
                                $pregc = '/category/'.$value['slug'].'/*';
                                array_push($del, $pregc);
                                unset($pregc);
                            }
                        }
                        self::del($del);
			return $param;#返回发布内容
                }else{
                    return;
                }
	}
    /**
     * 设置缓存
     *
     * @access public
     * @param string $anchor 锚点
     * @return void
     */
    public static function set($key, $cache)
    {
        global $mc;
        $installDb = Typecho_Db::get();
        $config  = Helper::options()->plugin(self::$pluginName);
        $expire = (intval($config->cacheTime)>1?intval($config->cacheTime):1)*24*60*60;
        if(is_array($cache)) $cache = json_encode($cache);
        if($config->cacheMode=='Mysql'){
        	$table = $installDb->getPrefix().self::$tableName;
        	$time = time();
        	
        	$cache = addslashes($cache);
			$sql = "REPLACE INTO $table  (`hash`,`cache`,`dateline`,`expire`) VALUES ('$key','$cache','$time','$expire')"; 
			#
        	$installDb->query($sql);
        }else{
			$mc = $mc?$mc:self::intSaeMc();
        	memcache_set($mc,'mk-'.$key,$cache,0,$expire);
        }
    }

    /**
     * 获取缓存
     *
     * @access public
     * @return void
     */
    public static function get($key)
    {
        global $mc;
		$installDb = Typecho_Db::get();
 		$config  = Helper::options()->plugin(self::$pluginName);
		if($config->cacheMode=='Mysql'){
			$row = $installDb->fetchRow($installDb->select('cache','dateline','expire')->from('table.'.self::$tableName)->where('hash = ?', $key));
			if(!$row) return;
			if(time()-$row['dateline']>$row['expire']) self::del($key);
			$cache =  $row['cache'];
		}else{
			$mc = $mc?$mc:self::intSaeMc();
        	$cache = trim(memcache_get($mc,'mk-'.$key));
        }
        $arr = json_decode($cache,true);        
        return is_array($arr)?$arr:$cache;
    }
    /**
     * 删除缓存
     * 
     * @access public
     * @return void
     */
    public static function del($cachekey)
    {
        global $mc;
		$installDb = Typecho_Db::get();
 		$config  = Helper::options()->plugin(self::$pluginName);
		$table = $installDb->getPrefix().self::$tableName;
                if(is_array($cachekey)){
                    foreach($cachekey as $k=>$v){
                        self::del($v);                
                    }
                }else{
			if($config->cacheMode=='Mysql'){
                            if($preg=strstr($cachekey, '*',TRUE)){
                                $where = $preg.'%';
                                $installDb->query("DELETE FROM $table WHERE `hash` LIKE '$where'");
                            }else{
                                $delete = $installDb->delete('table.'.self::$tableName)->where('hash = ?', $cachekey)->limit(1);
                                $installDb->query($delete);
                                //$installDb->query("DELETE FROM $table WHERE `hash`='$cachekey'");
                            }		
			}else{
				$mc = $mc?$mc:self::intSaeMc();
                                memcache_delete($mc,'mk-'.$cachekey);
                        }
                    }
    }
	/**
	 * SAE模式下 初始化memcache
	 * @
	 */
	private static function intSaeMc(){
		global $mc;
		
		$mc = memcache_init();
		return $mc;
	}
        
        public static function install()
	{           
                                
		$installDb = Typecho_Db::get();
		$prefix = $installDb->getPrefix();
                $cacheTable = $prefix. self::$tableName;  
		try {
                        $installDb->query("CREATE TABLE `$cacheTable` (
                        `hash`      varchar(200)  NOT NULL,
                        `cache`   text      NOT NULL,
                        `dateline` int(10) NOT NULL DEFAULT '0',
                        `expire`  int(8) NOT NULL DEFAULT '0',
                        UNIQUE KEY `hash` (`hash`)
                        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");

                       return('MostCache缓存表创建成功, 插件已经被激活!');
                    
		} catch (Typecho_Db_Exception $e) {
			$code = $e->getCode();
			if(('Mysql' == $type && 1050 == $code)) {
					$script = 'SELECT `hash`, `cache`, `dateline`, `expire` from `' . $cacheTable . '`';
					$installDb->query($script, Typecho_Db::READ);
					return '数据表已存在，插件启用成功';	
			} else {
				throw new Typecho_Plugin_Exception('数据表建立失败，插件启用失败。错误号：'.$code);
			}
		}
	}

    public static function personalConfig(\Typecho_Widget_Helper_Form $form) {
        
    }

}
