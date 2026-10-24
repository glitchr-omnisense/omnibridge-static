<?php ob_start(); ?>
<article>
    <h1><?= htmlspecialchars($post['title']) ?></h1>
    <p><?= htmlspecialchars($post['body']) ?></p>
</article>
<a href="../">All posts</a>
<?php $content = ob_get_clean(); $title = $post['title']; require __DIR__.'/layout.php';
