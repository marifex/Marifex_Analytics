<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

use Glpi\Plugin\Hooks;
use GlpiPlugin\Marifex\DashboardMenu;
use GlpiPlugin\Marifex\HomeDashboardTab;
use GlpiPlugin\Marifex\Profile;

define('PLUGIN_MARIFEX_VERSION', '0.15.2');
define('PLUGIN_MARIFEX_MIN_GLPI_VERSION', '11.0.7');
define('PLUGIN_MARIFEX_MAX_GLPI_VERSION', '12.0.0');
define('PLUGIN_MARIFEX_ROOT', __DIR__);

function plugin_init_marifex(): void
{
    global $PLUGIN_HOOKS;

    if (is_file(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    if (!class_exists(Plugin::class) || !Plugin::isPluginActive('marifex')) {
        return;
    }

    Plugin::registerClass(Profile::class, ['addtabon' => \Profile::class]);
    Plugin::registerClass(HomeDashboardTab::class, ['addtabon' => \Central::class]);

    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['marifex'] = [
        'tools' => DashboardMenu::class,
    ];
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['marifex'] = 'Settings';
    $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (is_string($requestPath) && (
        str_ends_with($requestPath, '/plugins/marifex/Dashboard')
        || str_ends_with($requestPath, '/plugins/marifex/Dashboard/Mobile')
        || str_ends_with($requestPath, '/front/central.php')
        || str_ends_with($requestPath, '/Central')
    )) {
        $PLUGIN_HOOKS[Hooks::ADD_CSS]['marifex'][] = 'css/marifex.css?v=0.15.2';
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['marifex'][] = 'js/dashboard.js?v=0.15.2';
    }
    if (is_string($requestPath) && str_ends_with($requestPath, '/plugins/marifex/Settings')) {
        $PLUGIN_HOOKS[Hooks::ADD_CSS]['marifex'][] = 'css/marifex.css?v=0.15.2';
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['marifex'][] = 'js/palette-manager.js?v=0.15.2';
    }
}

function plugin_version_marifex(): array
{
    return [
        'name' => 'MarifeX Advanced Analytics',
        'version' => PLUGIN_MARIFEX_VERSION,
        'author' => 'MarifeX',
        'license' => 'GPL-3.0-only',
        'homepage' => 'https://www.marifextech.com',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_MARIFEX_MIN_GLPI_VERSION,
                'max' => PLUGIN_MARIFEX_MAX_GLPI_VERSION,
            ],
            'php' => ['min' => '8.2.0'],
        ],
    ];
}

function plugin_marifex_check_prerequisites(): bool
{
    if (version_compare(PHP_VERSION, '8.2.0', '<')) {
        echo 'MarifeX requires PHP 8.2 or newer.';
        return false;
    }

    if (!defined('GLPI_VERSION') || version_compare(GLPI_VERSION, PLUGIN_MARIFEX_MIN_GLPI_VERSION, '<')) {
        echo 'MarifeX requires GLPI 11.0.7 or newer.';
        return false;
    }

    return version_compare(GLPI_VERSION, PLUGIN_MARIFEX_MAX_GLPI_VERSION, '<');
}

function plugin_marifex_check_config(bool $verbose = false): bool
{
    return true;
}
