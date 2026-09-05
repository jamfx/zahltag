<?php
// Install-Script ist nicht mehr zugänglich wenn config.php existiert
http_response_code(403);
die(e(__('install.success.title')));
