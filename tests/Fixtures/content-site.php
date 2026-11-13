<?php

// ContentSite as the command line reads it, its files in the folder the test made (Fixtures\ContentSite::make()).
use Omnibridge\Static\Tests\Fixtures\ContentSite;

require_once __DIR__.'/Folder.php';
require_once __DIR__.'/ContentSite.php';

return ContentSite::site(sys_get_temp_dir().'/omnibridge-static-content-'.getmypid(), cached: false);
