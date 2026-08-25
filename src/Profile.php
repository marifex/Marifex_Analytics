<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex;

use CommonGLPI;
use Html;
use ProfileRight;
use Session;

final class Profile extends \Profile
{
    public static $rightname = 'profile';

    public const RIGHT_DASHBOARD = 'plugin_marifex_dashboard';
    public const RIGHT_ADMIN = 'plugin_marifex_admin';
    public const RIGHT_EXPORT = 'plugin_marifex_export';
    public const RIGHT_SCHEDULE = 'plugin_marifex_schedule';

    public static function getAllRights($all = false): array
    {
        return [
            [
                'itemtype' => self::class,
                'label' => __('Export analytics reports', 'marifex'),
                'field' => self::RIGHT_EXPORT,
                'rights' => [READ => __('Read')],
            ],
            [
                'itemtype' => self::class,
                'label' => __('Schedule analytics reports', 'marifex'),
                'field' => self::RIGHT_SCHEDULE,
                'rights' => [READ => __('Read'), UPDATE => __('Update')],
            ],
            [
                'itemtype' => self::class,
                'label' => __('View analytics dashboards', 'marifex'),
                'field' => self::RIGHT_DASHBOARD,
                'rights' => [READ => __('Read')],
            ],
            [
                'itemtype' => self::class,
                'label' => __('Administer MarifeX analytics', 'marifex'),
                'field' => self::RIGHT_ADMIN,
                'rights' => [READ => __('Read'), UPDATE => __('Update')],
            ],
        ];
    }

    public static function getTypeName($nb = 0): string
    {
        return __('MarifeX Analytics', 'marifex');
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHT_DASHBOARD, READ);
    }

    public static function canAdminister(): bool
    {
        return (bool) Session::haveRight(self::RIGHT_ADMIN, UPDATE);
    }

    public static function canExport(): bool
    {
        return (bool) Session::haveRight(self::RIGHT_EXPORT, READ) || self::canAdminister();
    }

    public static function canSchedule(): bool
    {
        return (bool) Session::haveRight(self::RIGHT_SCHEDULE, UPDATE) || self::canAdminister();
    }

    public static function installRights(): void
    {
        global $DB;

        foreach ([self::RIGHT_DASHBOARD, self::RIGHT_EXPORT, self::RIGHT_SCHEDULE, self::RIGHT_ADMIN] as $right) {
            if ($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['name' => $right]])->count() === 0) {
                ProfileRight::addProfileRights([$right]);
            }
        }

        $privilegedProfiles = [];
        foreach ($DB->request([
            'SELECT' => ['profiles_id', 'rights'],
            'FROM' => 'glpi_profilerights',
            'WHERE' => ['name' => 'profile'],
        ]) as $profileRight) {
            if (((int) $profileRight['rights'] & UPDATE) === UPDATE) {
                $privilegedProfiles[] = (int) $profileRight['profiles_id'];
            }
        }

        foreach (array_unique($privilegedProfiles) as $profileId) {
            self::setProfileRights($profileId, [
                self::RIGHT_DASHBOARD => READ,
                self::RIGHT_EXPORT => READ,
                self::RIGHT_SCHEDULE => READ | UPDATE,
                self::RIGHT_ADMIN => READ | UPDATE,
            ]);
        }

        $activeProfile = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if ($activeProfile > 0 && Session::haveRight('profile', UPDATE) && !in_array($activeProfile, $privilegedProfiles, true)) {
            self::setProfileRights($activeProfile, [
                self::RIGHT_DASHBOARD => READ,
                self::RIGHT_EXPORT => READ,
                self::RIGHT_SCHEDULE => READ | UPDATE,
                self::RIGHT_ADMIN => READ | UPDATE,
            ]);
        }
    }

    public static function uninstallRights(): void
    {
        ProfileRight::deleteProfileRights([self::RIGHT_DASHBOARD, self::RIGHT_EXPORT, self::RIGHT_SCHEDULE, self::RIGHT_ADMIN]);
        unset(
            $_SESSION['glpiactiveprofile'][self::RIGHT_DASHBOARD],
            $_SESSION['glpiactiveprofile'][self::RIGHT_EXPORT],
            $_SESSION['glpiactiveprofile'][self::RIGHT_SCHEDULE],
            $_SESSION['glpiactiveprofile'][self::RIGHT_ADMIN]
        );
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        return $item instanceof \Profile ? self::createTabEntry(self::getTypeName()) : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof \Profile) {
            return false;
        }

        $profileId = (int) $item->getID();
        self::ensureProfileRows($profileId);
        $canEdit = Session::haveRight('profile', UPDATE);

        echo "<form method='post' action='" . $item->getFormURL() . "'>";
        $item->displayRightsChoiceMatrix(self::getAllRights(), [
            'canedit' => $canEdit,
            'title' => self::getTypeName(),
        ]);
        if ($canEdit) {
            echo Html::hidden('id', ['value' => $profileId]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
        }
        Html::closeForm();

        return true;
    }

    /** @param array<string, int> $rights */
    private static function setProfileRights(int $profileId, array $rights): void
    {
        global $DB;
        foreach ($rights as $name => $value) {
            $DB->updateOrInsert('glpi_profilerights', ['rights' => $value], [
                'profiles_id' => $profileId,
                'name' => $name,
            ]);
            if ((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0) === $profileId) {
                $_SESSION['glpiactiveprofile'][$name] = $value;
            }
        }
    }

    private static function ensureProfileRows(int $profileId): void
    {
        global $DB;
        foreach ([self::RIGHT_DASHBOARD, self::RIGHT_EXPORT, self::RIGHT_SCHEDULE, self::RIGHT_ADMIN] as $right) {
            if ($DB->request([
                'FROM' => 'glpi_profilerights',
                'WHERE' => ['profiles_id' => $profileId, 'name' => $right],
            ])->count() === 0) {
                $DB->insert('glpi_profilerights', ['profiles_id' => $profileId, 'name' => $right, 'rights' => 0]);
            }
        }
    }
}
