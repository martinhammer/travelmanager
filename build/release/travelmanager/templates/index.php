<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\TravelManager\AppInfo\Application::APP_ID, OCA\TravelManager\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\TravelManager\AppInfo\Application::APP_ID, OCA\TravelManager\AppInfo\Application::APP_ID . '-main');

?>

<div id="travelmanager"></div>
