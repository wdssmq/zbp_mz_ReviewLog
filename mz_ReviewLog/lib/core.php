<?php

class mz_ReviewLog_Core
{
  private static $configCache = null;
  private static $configCacheKey = null;
  private static $curWeekCache = null;
  private static $targetCateCache = null;
  private static $targetCateCacheKey = null;

  // 根据 offset 选择正式或 debug 配置路径
  private static function ResolveConfigPath()
  {
    if (self::DeBug() > 0) {
      return mz_ReviewLog_Path("config.debug");
    }
    return mz_ReviewLog_Path("config");
  }

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
    if ($fileConfig === null) {
      $fileConfig = self::ResolveConfigPath();
    }
    if (self::$configCache !== null && self::$configCacheKey === $fileConfig) {
      return self::$configCache;
    }
    // Debug 模式首次访问时自动生成独立配置，避免直接空配置。
    if (self::DeBug() > 0 && strpos($fileConfig, 'config.debug.json') !== false && !is_file($fileConfig)) {
      $baseFile = str_replace('config.debug.json', 'config.json', $fileConfig);
      if (is_file($baseFile)) {
        $baseConfig = self::LoadConfigArray($baseFile);
        if (is_array($baseConfig) && !empty($baseConfig)) {
          self::SaveConfigArray($baseConfig, $fileConfig);
        }
      }
    }
    $cacheData = array();
    if (is_file($fileConfig)) {
      $config = @file_get_contents($fileConfig);
      if ($config !== false && $config !== '') {
        $json = json_decode($config, true);
        if (is_array($json)) {
          $cacheData = $json;
        }
      }
    }
    self::$configCache = $cacheData;
    self::$configCacheKey = $fileConfig;
    return self::$configCache;
  }

  // 写入配置数组
  public static function SaveConfigArray($config, $fileConfig = null)
  {
    if ($fileConfig === null) {
      $fileConfig = self::ResolveConfigPath();
    }
    if (!is_array($config)) {
      $config = array();
    }
    $rlt = file_put_contents($fileConfig, json_encode($config));
    if ($rlt === false) {
      return false;
    }
    self::$configCache = $config;
    self::$configCacheKey = $fileConfig;
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
      $fileConfig = self::ResolveConfigPath();
    }
    $config = self::LoadConfigArray($fileConfig);
    if (empty($config)) {
      return false;
    }

    $curWeek = self::CurWeek();
    $lstWeek = isset($pickCate["lstWeek"]) ? intval($pickCate["lstWeek"]) : 0;
    $endID = (is_object($endPost) && isset($endPost->ID)) ? intval($endPost->ID) : -1;
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
    if ($fileConfig === null) {
      $fileConfig = self::ResolveConfigPath();
    }

    $curWeek = self::CurWeek();
    $cacheKey = $fileConfig . '|' . $curWeek;

    if (self::$targetCateCache !== null && self::$targetCateCacheKey === $cacheKey) {
      return self::$targetCateCache;
    }

    $config = self::LoadConfigArray($fileConfig);
    $result = null;

    if (!empty($config)) {
      $pickCate = null;
      $minWeek = null;

      foreach ($config as $cateItem) {
        if (!is_array($cateItem) || !isset($cateItem["lstWeek"])) {
          continue;
        }
        $lstWeek = intval($cateItem["lstWeek"]);
        if ($lstWeek === $curWeek) {
          $result = $cateItem;
          break;
        }
        if ($pickCate === null || $minWeek === null || $lstWeek < $minWeek) {
          $pickCate = $cateItem;
          $minWeek = $lstWeek;
        }
      }

      if ($result === null) {
        if ($pickCate !== null) {
          $result = $pickCate;
        } else {
          $result = isset($config[0]) ? $config[0] : null;
        }
      }
    }

    self::$targetCateCache = $result;
    self::$targetCateCacheKey = $cacheKey;
    return $result;
  }

  // 重置目标分类缓存
  public static function ResetTargetCateCache()
  {
    self::$targetCateCache = null;
    self::$targetCateCacheKey = null;
  }

  // 获取当前周数
  public static function CurWeek()
  {
    $offset = self::DeBug();

    if (self::$curWeekCache !== null) {
      return intval(self::$curWeekCache + $offset);
    }

    $timestamp = time();
    $week = ceil($timestamp / (7 * 86400));
    self::$curWeekCache = $week;
    return intval($week + $offset);
  }

  // 调试模式下的偏移量
  public static function DeBug($withConfig = false)
  {
    global $zbp;
    $offset = 0;
    // 优先从参数获取偏移量
    if (isset($_GET['offset']) && is_numeric($_GET['offset'])) {
      $offset = intval($_GET['offset']);
      if ($withConfig) {
        $zbp->Config('mz_ReviewLog')->offset = $offset;
        $zbp->SaveConfig('mz_ReviewLog');
      }
    } else if ($withConfig) {
      // 如果没有参数，则从配置中读取偏移量
      $cfg = $zbp->Config('mz_ReviewLog');
      $offset  = isset($cfg->offset) ? intval($cfg->offset) : 0;
    }
    if ($offset < 0) {
      $offset = 0;
    }
    return $offset;
  }
}
