<!-- {$cate.ID} | {$cate.Name} | {$cate.MinID} - {$cate.MaxID} -->

{foreach $articles as $article}
{if $article.Status == 0}
<li class="post">
  <!-- {$article.ID}、 -->
  <a target="_blank" href="{$article.Url}" title="{$article.Title}">{$article.Title}</a>
  <!-- 「{mz_ReviewLog_PostStatusConvert($article)}」 -->
</li>
{/if}
{/foreach}
