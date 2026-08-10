<?php
RegisterPlugin("mz_ReviewLog", "ActivePlugin_mz_ReviewLog");

function ActivePlugin_mz_ReviewLog()
{
  // 接口挂载 - 生成模板
  Add_Filter_Plugin('Filter_Plugin_Zbp_BuildTemplate', 'mz_ReviewLog_GenTpl');
  // 接口挂载 - 后台提示
  Add_Filter_Plugin('Filter_Plugin_Admin_Begin', 'mz_ReviewLog_SetHint');
  // 接口挂载 - 侧栏模块保存时防止 FileName 大小写变化
  Add_Filter_Plugin('Filter_Plugin_Module_Save', 'mz_ReviewLog_KeepModFileName');
  // 接口挂载 - Feed 自动触发函数
  Add_Filter_Plugin('Filter_Plugin_Feed_End', 'mz_ReviewLog_RandBuildMod');
}

// 接口函数 - 生成模板
function mz_ReviewLog_GenTpl(&$templates)
{
  // 生成模板
  $tplCont = @file_get_contents(mz_ReviewLog_Path("tpl-view-list"));
  if ($tplCont === false) {
    $tplCont = '';
  }
  $templates["plugin_mz_ReviewLog_view-list"] = $tplCont;
  $tplMod = @file_get_contents(mz_ReviewLog_Path("tpl-view-mod"));
  if ($tplMod === false) {
    $tplMod = '';
  }
  $templates["plugin_mz_ReviewLog_view-mod"] = $tplMod;

  // 随机触发构建侧栏模块
  mz_ReviewLog_RandBuildMod(7);
}

// 接口函数 - 后台提示
function mz_ReviewLog_SetHint()
{
  global $zbp;
  if (!$zbp->CheckPlugin('mz_Notice')) {
    return;
  }
  $data = array(
    "Source" => "ReviewLog",
    "Title" => "查看过往文章",
    "Url" => mz_ReviewLog_Path("main", "host"),
    "CycleDays" => 4,
  );
  mz_Notice_SetHintCycle($data);
}

// 接口函数 - 侧栏模块保存时防止 FileName 大小写变化
function mz_ReviewLog_KeepModFileName(&$mod)
{
  // 如果 $mod->FileName 为全小写
  if ("reviewlog-mod" === $mod->FileName) {
    // 将 $mod->FileName 恢复为原始值
    $mod->FileName = "ReviewLog-Mod";
  }
}

// 接口函数 - Feed 自动触发函数
function mz_ReviewLog_RandBuildMod($max = 37)
{
  if (!is_numeric($max)) {
    $max = 37;
  }
  $max = intval($max);
  if ($max < 1) {
    $max = 1;
  }
  // 随机触发构建侧栏模块
  if (mt_rand(1, $max) <= 4) {
    mz_ReviewLog_BuildMod();
  }
}

function InstallPlugin_mz_ReviewLog()
{
  global $zbp;
  // 创建目录
  $dir = mz_ReviewLog_Path("usr");
  if (!is_dir($dir)) {
    @mkdir($dir, 0755);
  }
  // 初始化配置文件
  $file = mz_ReviewLog_Path("config");
  if (!is_file($file)) {
    mz_ReviewLog_InitConfig($file);
  }
  $zbp->BuildTemplate();
}

function UninstallPlugin_mz_ReviewLog() {}

// ----------

// 写入通知插件
function mz_ReviewLog_WriteNotice($con)
{
  global $zbp;
  if (!$zbp->CheckPlugin('mz_Notice')) {
    return;
  }
  $data = array(
    "Title" => "查看过往文章 -  本周旧文",
    "Text" => $con,
    "Url" => mz_ReviewLog_Path("main", "host"),
    "Source" => "mz_ReviewLog",
  );
  mz_Notice_PostItem($data, 86400 * 4);
}

// 获取文章列表
function mz_ReviewLog_ViewPostList($act = "return", $tpl = "mod")
{
  global $zbp;

  // 模板名称
  $tplName = "plugin_mz_ReviewLog_view-{$tpl}";
  // 判断模板是否存在
  $isHasTpl = $zbp->template->HasTemplate($tplName);

  // 判断是否用于前台模块
  $isForMod = $tpl === "mod";
  // 读取配置文件
  $pickCate = mz_ReviewLog_GetTgtCate();
  if (!is_array($pickCate) || !isset($pickCate["cateID"])) {
    $rlt = "暂无可用分类配置";
    if ($act === "echo") {
      echo $rlt;
      return;
    }
    return $rlt;
  }
  // 按照 ID 顺序获取文章
  $articles = mz_ReviewLog_GetPostByCate($pickCate, array("byId" => true, "forMod" => $isForMod));
  if (!is_array($articles)) {
    $articles = array();
  }
  if ($isForMod) {
    // 按照浏览量顺序获取文章，合并到数组
    $articlesByView = mz_ReviewLog_GetPostByCate($pickCate, array("byId" => false, "forMod" => $isForMod));
    if (is_array($articlesByView)) {
      $articles = array_merge($articles, $articlesByView);
    }
  } else {
    $endPost = empty($articles) ? null : end($articles);
    mz_ReviewLog_UpdateConfig($pickCate, $endPost);
  }
  $cate = (object) array(
    "ID" => isset($pickCate["cateID"]) ? $pickCate["cateID"] : 0,
    "Name" => isset($pickCate["cateName"]) ? $pickCate["cateName"] : "",
    "MinID" => isset($pickCate["range"]["MinID"]) ? $pickCate["range"]["MinID"] : 0,
    "MaxID" => isset($pickCate["range"]["MaxID"]) ? $pickCate["range"]["MaxID"] : 0,
  );
  // 对 $articles 去重
  $articles = array_unique($articles, SORT_REGULAR);
  if ($isHasTpl) {
    // 按模板输出
    $zbp->template->SetTags('cate', $cate);
    $zbp->template->SetTags('articles', $articles);
    $rlt = $zbp->template->Output($tplName);
  } else {
    $rlt = "模板不存在";
  }
  if ($act === "echo") {
    echo $rlt;
  } else {
    return $rlt;
  }
}

// 生成或更新侧栏模块
function mz_ReviewLog_BuildMod()
{
  global $zbp;
  // 获取文章内容
  $modCon = mz_ReviewLog_ViewPostList("return", "mod");
  if (!is_string($modCon)) {
    $modCon = '';
  }
  $zbp->LoadModules();
  // $mod = $zbp->modulesbyfilename["ReviewLog-Mod"];
  $mod = $zbp->GetModuleByFileName("ReviewLog-Mod");
  if ($mod->ID == 0) {
    $mod->Name = "「ReviewLog」本周旧文";
    $mod->FileName = "ReviewLog-Mod";
    $mod->HtmlID = $mod->FileName;
    $mod->Source = "plugin_ReviewLog";
  }
  $mod->Type = "ul";
  $mod->Content = $modCon;
  $mod->Save();
  // 输出至通知插件
  mz_ReviewLog_WriteNotice($modCon);
}

// 获取指定分类的文章
function mz_ReviewLog_GetPostByCate($cateConfig, $opt = array("byId" => false, "forMod" => false))
{
  global $zbp;
  if (!is_array($cateConfig) || !isset($cateConfig["cateID"])) {
    return array();
  }
  $opt = array_merge(array("byId" => false, "forMod" => false), (array)$opt);
  // ----
  $cateID = $cateConfig["cateID"];
  $lstID = $cateConfig["lstID"];
  // ----
  $w = array();
  $w[] = array("=", "log_CateID", $cateID);
  // ----
  if ($opt["byId"]) {
    $w[] = array(">=", "log_ID", $lstID);
    $order = array('log_ID' => 'ASC');
  } else {
    $order = array('log_ViewNums' => 'ASC');
  }
  $limit = 4;
  // ----
  if ($opt["forMod"]) {
    $w[] = array('=', 'log_Status', '0');
  }
  // ----
  $postList = $zbp->GetPostList(
    array('*'),
    $w,
    $order,
    $limit,
    null
  );
  return $postList;
}

// 更新配置文件
function mz_ReviewLog_UpdateConfig($pickCate, $endPost)
{
  if (!is_array($pickCate) || !isset($pickCate["cateID"])) {
    return false;
  }
  // 读取配置文件
  $file = mz_ReviewLog_Path("config");
  $config = mz_ReviewLog_LoadConfigArray();
  if (empty($config)) {
    return false;
  }

  $curWeek = mz_ReviewLog_CurWeek();
  $lstWeek = isset($pickCate["lstWeek"]) ? intval($pickCate["lstWeek"]) : 0;
  $endID = (is_object($endPost) && isset($endPost->ID)) ? intval($endPost->ID) : (isset($pickCate["lstID"]) ? intval($pickCate["lstID"]) : 0);
  $newID = $endID + 1;

  $bolRlt = false;

  if ($curWeek != $lstWeek) {
    foreach ($config as $key => &$item) {
      if (!isset($item["cateID"])) {
        continue;
      }
      if ($item["cateID"] == $pickCate["cateID"]) {
        $isFirstCycle = !isset($item["cycled"]) || intval($item["cycled"]) === 0;
        $item["lstWeek"] = $curWeek;
        if ($isFirstCycle) {
          // 首次轮到该分类时仅锁定到当前周，不推进 lstID。
          $item["cycled"] = 1;
        } else {
          $maxID = isset($item["range"]["MaxID"]) ? intval($item["range"]["MaxID"]) : 0;
          $minID = isset($item["range"]["MinID"]) ? intval($item["range"]["MinID"]) : 0;
          $item["lstID"] = $newID <= $maxID ? $newID : $minID;
        }
      }
      $itemLstID = isset($item["lstID"]) ? intval($item["lstID"]) : 0;
      $itemMinID = isset($item["range"]["MinID"]) ? intval($item["range"]["MinID"]) : 0;
      if ($itemLstID <= $itemMinID) {
        $objRange = mz_ReviewLog_QueryRangeByCate($item["cateID"]);
        $item["range"] = array("MinID" => $objRange->MinID, "MaxID" => $objRange->MaxID);
      }
    }
    file_put_contents($file, json_encode($config));
    $bolRlt = true;
  }

  return $bolRlt;
}

// 获取当前目标分类
function mz_ReviewLog_GetTgtCate()
{
  // 读取配置文件
  $config = mz_ReviewLog_LoadConfigArray();
  if (empty($config)) {
    return null;
  }

  // 当前是第几周
  $week = intval(mz_ReviewLog_CurWeek());
  $pickCate = null;
  $minWeek = null;

  foreach ($config as $cateItem) {
    if (!is_array($cateItem) || !isset($cateItem["lstWeek"])) {
      continue;
    }
    $lstWeek = intval($cateItem["lstWeek"]);
    if ($lstWeek === $week) {
      return $cateItem;
    }
    if ($pickCate === null || $minWeek === null || $lstWeek < $minWeek) {
      $pickCate = $cateItem;
      $minWeek = $lstWeek;
    }
  }

  if ($pickCate !== null) {
    return $pickCate;
  }
  return isset($config[0]) ? $config[0] : null;
}

// 初始化配置文件
function mz_ReviewLog_InitConfig($fileConfig)
{
  global $zbp;
  $zbp->LoadCategorys();
  $arrCateList = $zbp->categories;
  $arrConfig = array();
  $lstWeek = mz_ReviewLog_CurWeek() - 1;
  foreach ($arrCateList as $Cate) {
    $cateName = $Cate->Name;
    $cateID = $Cate->ID;
    $objRange = mz_ReviewLog_QueryRangeByCate($cateID);
    $cateConfig = array(
      "cateName" => $cateName,
      "cateID" => $cateID,
      "lstID" => $objRange->MinID,
      "lstWeek" => $lstWeek,
      "cycled" => 0,
      "range" => array("MinID" => $objRange->MinID, "MaxID" => $objRange->MaxID),
    );
    $arrConfig[$cateID] = $cateConfig;
  }
  // 排序
  ksort($arrConfig);
  // 转无序数组
  $arrConfig = array_values($arrConfig);
  file_put_contents($fileConfig, json_encode($arrConfig));
}

// 查询指定分类的文章 ID 范围
function mz_ReviewLog_QueryRangeByCate($cateID)
{
  global $zbp;
  $cateID = intval($cateID);
  $sql = $zbp->db->sql->Select(
    $zbp->table['Post'],
    array("MIN(log_ID)", "MAX(log_ID)"),
    array(
      array('=', 'log_Type', '0'),
      array('=', 'log_CateID', $cateID),
      // array('=', 'log_Status', '0'),
    )
  );
  $rltQuery = $zbp->db->Query($sql);
  $min = 0;
  $max = 0;
  if (is_array($rltQuery) && isset($rltQuery[0])) {
    $row = is_array($rltQuery[0]) ? array_values($rltQuery[0]) : array();
    if (isset($row[0]) && is_numeric($row[0])) {
      $min = intval($row[0]);
    }
    if (isset($row[1]) && is_numeric($row[1])) {
      $max = intval($row[1]);
    }
  }
  if ($min < 0) {
    $min = 0;
  }
  if ($max < $min) {
    $max = $min;
  }
  return (object)array("MinID" => $min, "MaxID" => $max);
}

// 时间戳转周数
function mz_ReviewLog_CurWeek()
{
  $timestamp = time();
  $week = ceil($timestamp / (7 * 86400));
  // $week = ceil($timestamp / (7 * 86400)) + mz_ReviewLog_DeBug();
  return $week;
}

function mz_ReviewLog_DeBug()
{
  global $zbp;
  $offset = $zbp->Config('mz_ReviewLog')->offset;
  if (is_numeric($offset)) {
    $offset = intval($offset);
  } else {
    $offset = 0;
  }
  if (!$zbp->Config('mz_ReviewLog')->HasKey("flag")) {
    $zbp->Config('mz_ReviewLog')->offset = $offset + 1;
    $zbp->SaveConfig('mz_ReviewLog');
  }
  $zbp->Config('mz_ReviewLog')->flag = true;
  return $offset;
}

// 文章状态转换
function mz_ReviewLog_PostStatusConvert($arg)
{
  if (is_numeric($arg)) {
    $status = intval($arg);
  } else {
    $status = $arg->Status;
  }
  $map = array(
    0 => "公开",
    1 => "草稿",
    2 => "审核",
  );
  return isset($map[$status]) ? $map[$status] : "未知";
}

// 路径获取函数
function mz_ReviewLog_Path($file, $t = 'path')
{
  global $zbp;
  if ($t !== 'path' && $t !== 'host') {
    $t = 'path';
  }
  $result = $zbp->$t . 'zb_users/plugin/mz_ReviewLog/';
  switch ($file) {
    case 'tpl-view-list':
      return $result . 'tpl/view-list.php';
      break;
    case 'tpl-view-mod':
      return $result . 'tpl/view-mod.php';
      break;
    case 'config':
      return $result . 'usr/config.json';
      break;
    case 'usr':
      return $result . 'usr/';
      break;
    case 'var':
      return $result . 'var/';
      break;
    case 'main':
      return $result . 'main.php';
      break;
    default:
      return $result . $file;
  }
}

// 加载配置数组
function mz_ReviewLog_LoadConfigArray()
{
  $file = mz_ReviewLog_Path("config");
  if (!is_file($file)) {
    return array();
  }
  $config = @file_get_contents($file);
  if ($config === false || $config === '') {
    return array();
  }
  $json = json_decode($config, true);
  if (!is_array($json)) {
    return array();
  }
  return $json;
}
