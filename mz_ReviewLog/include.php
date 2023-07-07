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
}

// 接口函数 - 生成模板
function mz_ReviewLog_GenTpl(&$templates)
{
  // 并不是每次都需要执行，所以加个随机
  $rand = rand(1, 4);
  if ($rand === 1) {
    // 生成模板
    $tplCont = file_get_contents(mz_ReviewLog_Path("tpl-view-list"));
    $templates["plugin_mz_ReviewLog_view-list"] = $tplCont;
    $tplMod = file_get_contents(mz_ReviewLog_Path("tpl-view-mod"));
    $templates["plugin_mz_ReviewLog_view-mod"] = $tplMod;
  }
  if ($rand === 2 && isset($templates["plugin_mz_ReviewLog_view-mod"])) {
    // 生成侧栏模块
    mz_ReviewLog_BuildMod();
  }
}

// 接口函数 - 后台提示
function mz_ReviewLog_SetHint()
{
  global $zbp;
  if (!$zbp->CheckPlugin('mz_Notice')) {
    return;
  }
  $data = array(
    "ID" => "ReviewLog",
    "Title" => "查看过往文章",
    "Link" => mz_ReviewLog_Path("main", "host"),
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

function InstallPlugin_mz_ReviewLog()
{
  global $zbp;
  // 创建目录
  $dir = mz_ReviewLog_Path("usr");
  if (!is_dir($dir)) {
    @mkdir($dir, 0755);
  }
  // 初始化配置文件
  $file = mz_ReviewLog_Path("config");;
  if (!is_file($file)) {
    mz_ReviewLog_InitConfig($file);
  }
  $zbp->BuildTemplate();
}

function UninstallPlugin_mz_ReviewLog()
{
}

// ----------

function mz_ReviewLog_ViewPostList($act = "return", $tpl = "mod")
{
  global $zbp;
  // 判断是否用于前台模块
  $isForMod = $tpl === "mod";
  // 读取配置文件
  $pickCate = mz_ReviewLog_ReadConfig();
  // 按照 ID 顺序获取文章
  $articles = mz_ReviewLog_GetPostByCate($pickCate, array("byId" => true, "forMod" => $isForMod));
  if ($isForMod) {
    // 按照浏览量顺序获取文章，合并到数组
    $articles = array_merge($articles, mz_ReviewLog_GetPostByCate($pickCate, array("byId" => false, "forMod" => $isForMod)));
  } else {
    $isNewWeek = mz_ReviewLog_UpdateConfig($pickCate, end($articles));
  }
  $cate = (object) array(
    "ID" => $pickCate["cateID"],
    "Name" => $pickCate["cateName"],
    "MinID" => $pickCate["range"]["MinID"],
    "MaxID" => $pickCate["range"]["MaxID"],
  );
  // 对 $articles 去重
  $articles = array_unique($articles, SORT_REGULAR);
  // 按模板输出
  $zbp->template->SetTags('cate', $cate);
  $zbp->template->SetTags('articles', $articles);
  $rlt = $zbp->template->Output("plugin_mz_ReviewLog_view-{$tpl}");
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
}

// 获取指定分类的文章
function mz_ReviewLog_GetPostByCate($cateConfig, $opt = array("byId" => false, "forMod" => false))
{
  global $zbp;
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
  // 读取配置文件
  $file = mz_ReviewLog_Path("config");
  $config = file_get_contents($file);
  $config = json_decode($config, true);

  $curWeek = mz_ReviewLog_CurWeek();
  $lstWeek = $pickCate["lstWeek"];
  $newID = $endPost->ID + 1;

  $bolRlt = false;

  if ($curWeek != $lstWeek) {
    foreach ($config as $key => &$item) {
      if ($item["cateID"] == $pickCate["cateID"]) {
        $item["lstWeek"] = $curWeek;
        $item["lstID"] = $newID <= $item["range"]["MaxID"] ? $newID : $item["range"]["MinID"];
      }
      if ($item["lstID"] <= $item["range"]["MinID"]) {
        $objRange = mz_ReviewLog_QueryRangeByCate($item["cateID"]);
        $item["range"] = $objRange;
      }
    }
    file_put_contents($file, json_encode($config));
    $bolRlt = true;
  }

  return $bolRlt;
}

// 读取配置文件
function mz_ReviewLog_ReadConfig()
{
  // 读取配置文件
  $file = mz_ReviewLog_Path("config");
  $config = file_get_contents($file);
  $config = json_decode($config, true);

  // 获取数量
  $len = count($config);
  // 当前是第几周
  $week = mz_ReviewLog_CurWeek();
  // $week mod $len
  $mod = $week % $len;

  return $config[$mod];
}

// 初始化配置文件
function mz_ReviewLog_InitConfig($fileConfig)
{
  global $zbp;
  $zbp->LoadCategorys();
  $arrCateList = $zbp->categories;
  $arrConfig = array();
  $lstWeek = mz_ReviewLog_CurWeek();
  foreach ($arrCateList as $Cate) {
    $cateName = $Cate->Name;
    $cateID = $Cate->ID;
    $objRange = mz_ReviewLog_QueryRangeByCate($cateID);
    $cateConfig = array(
      "cateName" => $cateName,
      "cateID" => $cateID,
      "lstID" => $objRange->MinID,
      "lstWeek" => $lstWeek,
      "range" => $objRange,
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
  list($min, $max) = explode(':', join(":", $rltQuery[0]));
  return (object)array("MinID" => ceil($min), "MaxID" => ceil($max));
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
  return $map[$status];
}

function mz_ReviewLog_Path($file, $t = 'path')
{
  global $zbp;
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
