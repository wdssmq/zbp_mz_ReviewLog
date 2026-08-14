<h3>
  {$cate.ID} | {$cate.Name} | {$cate.MinID} - {$cate.MaxID} | {$curWeek}
</h3>

{foreach $articles as $article}
<p class="post post-{$article.ID}" data-id="{$article.ID}">
  {$article.ID}、<a target="_blank" href="{$article.Url}" title="{$article.Title}">{$article.Title}</a>「{mz_ReviewLog_PostStatusConvert($article)}」
</p>
{/foreach}
