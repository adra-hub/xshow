<?php
require_once __DIR__ . '/config.php';

// Check if XShow is installed
if (!Config::isInstalled()) {
    header('Location: install.php');
    exit;
}

// Continue with your existing xshow.php code
require_once __DIR__ . '/xshow.php';