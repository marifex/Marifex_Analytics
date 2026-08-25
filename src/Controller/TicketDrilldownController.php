<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Controller;

use DBmysql;
use Glpi\Controller\AbstractController;
use GlpiPlugin\Marifex\Profile;
use GlpiPlugin\Marifex\Security\EntityScope;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class TicketDrilldownController extends AbstractController
{
    #[Route('/drilldown/tickets', name: 'marifex_ticket_drilldown', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        if (!Profile::canView()) {
            throw new AccessDeniedHttpException();
        }

        $groupId = $request->query->getInt('group_id');
        if ($groupId < 0) {
            throw new BadRequestHttpException('Invalid group filter.');
        }

        $target = '/front/ticket.php';
        if ($groupId > 0) {
            global $DB;
            if (!$DB instanceof DBmysql) {
                throw new AccessDeniedHttpException();
            }

            $entityScope = new EntityScope();
            $group = $DB->request([
                'SELECT' => ['id'],
                'FROM' => 'glpi_groups',
                'WHERE' => ['id' => $groupId, 'entities_id' => $entityScope->activeEntityIds()],
                'LIMIT' => 1,
            ])->current();
            if (!$group) {
                throw new AccessDeniedHttpException();
            }

            $query = http_build_query([
                'criteria' => [[
                    'field' => 8,
                    'searchtype' => 'equals',
                    'value' => $groupId,
                    'link' => 'AND',
                ]],
            ]);
            $target .= '?' . $query;
        }

        return new RedirectResponse($target, Response::HTTP_FOUND, ['Cache-Control' => 'private, no-store']);
    }
}
