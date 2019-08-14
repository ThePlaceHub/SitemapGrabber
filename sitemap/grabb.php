<?php
/**
 * Freedom Engine
 * import.php
 *
 * @copyright Copyright (c) 2019
 */
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?: 'C:/apache/freedom.local/www';
define("ROOT", $_SERVER['DOCUMENT_ROOT']);
require_once ROOT . "/config.php";
require_once ROOT . "/lib/runtime.php";
require_once ROOT . "/lib/simple_html_dom.php";
require_once ROOT . "/lib/random-user-agent.php";
require_once ROOT . "/lib/random-proxy.php";
require_once ROOT . "/lib/yandexxml.php";
require_once ROOT . "/grabbers/Base_Grabber.php";
require_once ROOT . "/grabbers/sitemap/Sitemap_Grabber.php";

$grabber = new Sitemap_Grabber($settings);
$grabber->run();