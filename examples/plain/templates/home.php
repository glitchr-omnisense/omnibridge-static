<?php ob_start(); ?>
<h1>Posts</h1>
<ul>
<?php foreach ($posts as $slug => $post) { ?>
    <li><a href="/posts/<?= $slug ?>"><?= htmlspecialchars($post['title']) ?></a></li>
<?php } ?>
</ul>
<?php $content = ob_get_clean(); $title = 'Example'; require __DIR__.'/layout.php';
