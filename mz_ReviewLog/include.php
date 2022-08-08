<?php
RegisterPlugin("mz_ReviewLog", "ActivePlugin_mz_ReviewLog");

function ActivePlugin_mz_ReviewLog()
{
  Add_Filter_Plugin('Filter_Plugin_Zbp_BuildTemplate', 'mz_ReviewLog_GenTpl');
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

// ------
function mz_ReviewLog_GenTpl(&$templates)
{
  $tplCont = file_get_contents(mz_ReviewLog_Path("tpl-view-list"));
  $templates["plugin_mz_ReviewLog_view-list"] = $tplCont;
}

// ------
function mz_ReviewLog_ViewPostList()
{
  global $zbp;
  $pickCate = mz_ReviewLog_ReadConfig();
  $articles = mz_ReviewLog_GetPostByCate($pickCate);
  $isNewWeek = mz_ReviewLog_UpdateConfig($pickCate, end($articles));
  // $cate = $zbp->GetCategoryByID($pickCate["cateID"]);
  $cate = (object) array(
    "ID" => $pickCate["cateID"],
    "Name" => $pickCate["cateName"],
    "MinID" => $pickCate["range"]["MinID"],
    "MaxID" => $pickCate["range"]["MaxID"],
  );
  $zbp->template->SetTags('cate', $cate);
  $zbp->template->SetTags('articles', $articles);
  $rlt = $zbp->template->Output("plugin_mz_ReviewLog_view-list");
  echo $rlt;
}

function mz_ReviewLog_GetPostByCate($cateConfig)
{
  global $zbp;
  // ----
  $cateID = $cateConfig["cateID"];
  $lstID = $cateConfig["lstID"];
  // ----
  $w = array();
  $w[] = array("=", "log_CateID", $cateID);
  $w[] = array(">=", "log_ID", $lstID);
  $order = array('log_ID' => 'ASC');
  $limit = 4;
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
