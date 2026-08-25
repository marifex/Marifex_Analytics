<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Report;

use GLPIMailer;
use RuntimeException;
use Symfony\Component\Mime\Address;

final class ReportEmailDelivery
{
    /** @param array<string, mixed> $schedule
     *  @param array<string, mixed> $artifact
     */
    public function send(array $schedule, array $artifact): void
    {
        global $CFG_GLPI;
        $recipients = json_decode((string) $schedule['recipients'], true, 32, JSON_THROW_ON_ERROR);
        if ($recipients === []) {
            throw new RuntimeException('The scheduled report has no recipients.');
        }
        $mailer = new GLPIMailer();
        $email = $mailer->getEmail();
        $email->from(new Address((string) $CFG_GLPI['admin_email'], (string) $CFG_GLPI['admin_email_name']));
        $email->to(...array_map(static fn(string $address): Address => new Address($address), $recipients));
        $email->subject('MarifeX report: ' . (string) $schedule['name']);
        $email->text("Your scheduled MarifeX dashboard report is attached.\n\nSchedule: " . (string) $schedule['name']);
        $email->html('<p>Your scheduled MarifeX dashboard report is attached.</p><p><strong>Schedule:</strong> ' . htmlspecialchars((string) $schedule['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>');
        $email->attachFromPath((string) $artifact['path'], (string) $artifact['name'], $artifact['format'] === 'pdf' ? 'application/pdf' : 'text/csv');
        if (!$mailer->send()) {
            throw new RuntimeException('GLPI mail delivery failed: ' . ($mailer->getError() ?? 'unknown error'));
        }
    }
}
