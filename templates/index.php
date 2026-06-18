<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\Simplenavigation\AppInfo\Application::APP_ID, OCA\Simplenavigation\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\Simplenavigation\AppInfo\Application::APP_ID, OCA\Simplenavigation\AppInfo\Application::APP_ID . '-main');

?>

<div id="simplenavigation"></div>
