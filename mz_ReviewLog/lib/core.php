<?php

class mz_ReviewLog_Core
{

  private static $configCache = null;
  private static $curWeekCache = null;
  private static $debugWeekCache = null;
  private static $targetCateCache = null;

  // 初始化配置文件
  public static function InitConfig($fileConfig)
  {
    global $zbp;
    $zbp->LoadCategorys();
    $arrCateList = $zbp->categories;
    $arrConfig = array();
    $lstWeek = self::CurWeek() - 1;
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
    ksort($arrConfig);
    $arrConfig = array_values($arrConfig);
    self::SaveConfigArray($arrConfig, $fileConfig);
  }

  // 读取配置数组
  public static function LoadConfigArray($fileConfig = null)
  {
    if (self::$configCache !== null) {
      return self::$configCache;
    }
    if ($fileConfig === null) {
      $fileConfig = mz_ReviewLog_Path("config");
    }
    if (!is_file($fileConfig)) {
      self::$configCache = array();
      return self::$configCache;
    }
    $config = @file_get_contents($fileConfig);
    if ($config === false || $config === '') {
      self::$configCache = array();
      return self::$configCache;
    }
    $json = json_decode($config, true);
    if (!is_array($json)) {
      self::$configCache = array();
      return self::$configCache;
    }
    self::$configCache = $json;
    return self::$configCache;
  }

  // 写入配置数组
  public static function SaveConfigArray($config, $fileConfig = null)
  {
    if ($fileConfig === null) {
      $fileConfig = mz_ReviewLog_Path("config");
    }
    if (!is_array($config)) {
      $config = array();
    }
    $rlt = file_put_contents($fileConfig, json_encode($config));
    if ($rlt === false) {
      return false;
    }
    self::$configCache = $config;
    self::ResetTargetCateCache();
    return true;
  }

  // 更新配置文件
  public static function UpdateConfig($pickCate, $endPost, $fileConfig = null)
  {
    if (!is_array($pickCate) || !isset($pickCate["cateID"])) {
      return false;
    }
    if ($fileConfig === null) {
      $fileConfig = mz_ReviewLog_Path("config");
    }
    $config = self::LoadConfigArray($fileConfig);
    if (empty($config)) {
      return false;
    }

    $curWeek = self::CurWeek();
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
      unset($item);
      $bolRlt = self::SaveConfigArray($config, $fileConfig);
    }

    return $bolRlt;
  }

  // 获取当前目标分类
  public static function GetTgtCate($fileConfig = null)
  {
    if (self::$curWeekCache !== null && self::$targetCateCache !== null) {
      return self::$targetCateCache;
    }
    $config = self::LoadConfigArray($fileConfig);
    $curWeek = self::CurWeek();
    if (empty($config)) {
      self::$targetCateCache = null;
      return null;
    }

    $pickCate = null;
    $minWeek = null;

    foreach ($config as $cateItem) {
      if (!is_array($cateItem) || !isset($cateItem["lstWeek"])) {
        continue;
      }
      $lstWeek = intval($cateItem["lstWeek"]);
      if ($lstWeek === $curWeek) {
        self::$targetCateCache = $cateItem;
        return $cateItem;
      }
      if ($pickCate === null || $minWeek === null || $lstWeek < $minWeek) {
        $pickCate = $cateItem;
        $minWeek = $lstWeek;
      }
    }

    if ($pickCate !== null) {
      self::$targetCateCache = $pickCate;
      return $pickCate;
    }

    self::$targetCateCache = isset($config[0]) ? $config[0] : null;
    return self::$targetCateCache;
  }

  // 重置目标分类缓存
  public static function ResetTargetCateCache()
  {
    self::$targetCateCache = null;
    self::$curWeekCache = null;
  }

  // 获取当前周数
  public static function CurWeek()
  {

    if (self::$curWeekCache !== null) {
      return self::$curWeekCache;
    }
    $timestamp = time();
    $week = ceil($timestamp / (7 * 86400));
    self::$curWeekCache = $week;
    $offset = self::DeBug();
    if ($offset > 0) {
      self::$debugWeekCache = $week + $offset;
    }
    return $week;
  }

  // 调试模式下的偏移量
  public static function DeBug()
  {
    $offset = 0;
    if (isset($_GET['offset']) && is_numeric($_GET['offset'])) {
      $offset = intval($_GET['offset']);
    }
    if ($offset < 0) {
      $offset = 0;
    }
    return $offset;
  }
}
