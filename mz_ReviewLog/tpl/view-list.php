<h3>
  {$cate.ID} | {$cate.Name} | {$cate.MinID} - {$cate.MaxID}
</h3>

{foreach $articles as $article}
<p>
  {$article.ID}、<a href="{$article.Url}" title="{$article.Title}">{$article.Title}</a>「{mz_ReviewLog_PostStatusConvert($article)}」
</p>
{/foreach}
