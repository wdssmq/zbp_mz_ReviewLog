<?php
require '../../../zb_system/function/c_system_base.php';
require '../../../zb_system/function/c_system_admin.php';
$zbp->Load();
$action = 'root';
if (!$zbp->CheckRights($action)) {
  $zbp->ShowError(6);
  die();
}
if (!$zbp->CheckPlugin('mz_ReviewLog')) {
  $zbp->ShowError(48);
  die();
}

if (count($_POST) > 0) {
  CheckIsRefererValid();
}

InstallPlugin_mz_ReviewLog();

$pickCate = mz_ReviewLog_ReadConfig();

$blogtitle = '旧文章回顾';
require $blogpath . 'zb_system/admin/admin_header.php';
require $blogpath . 'zb_system/admin/admin_top.php';
?>
<link rel="stylesheet" href="css/style.css">
<div id="divMain">
  <div class="divHeader"><?php echo $blogtitle; ?></div>
  <div class="SubMenu">
    <a href="main.php" title="首页"><span class="m-left m-now">首页</span></a>
    <?php require "about.php"; ?>
  </div>
  <div id="divMain2">
    <?php mz_ReviewLog_ViewPostList("echo", "list"); ?>
    <hr>
    <p>旧文章回顾 - Z-Blog 应用中心</p>
    <p><a href="https://app.zblogcn.com/?id=31464" target="_blank" title="旧文章回顾 - Z-Blog 应用中心">https://app.zblogcn.com/?id=31464</a></p>
  </div>
</div>

<?php
require $blogpath . 'zb_system/admin/admin_footer.php';
RunTime();
?>
