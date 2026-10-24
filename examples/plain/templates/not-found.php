<?php ob_start(); ?>
<h1>Not found</h1>
<p><a href="/">Home</a></p>
<?php $content = ob_get_clean(); $title = 'Not found'; require __DIR__.'/layout.php';
