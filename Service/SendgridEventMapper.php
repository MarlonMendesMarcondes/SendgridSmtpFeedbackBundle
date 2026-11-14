<?php

declare(strict_types=1);

namespace MauticPlugin\SendgridSmtpFeedbackBundle\Service;

use Mautic\LeadBundle\Entity\DoNotContact;

/**
 * Decide o que um evento do SendGrid Event Webhook vira no Mautic.
 *
 * Retorna null quando o evento não deve marcar nada (entregue, aberto,
 * bloqueio temporário, descadastro com a opção desligada...).
 */
class SendgridEventMapper
{
    /**
     * Motivos de "dropped" que significam endereço que não recebe e-mail.
     */
    private const DROPPED_BOUNCE_REASONS = ['bounced address', 'invalid'];

    /**
     * Motivos de "dropped" que significam que a pessoa não quer receber.
     */
    private const DROPPED_UNSUBSCRIBE_REASONS = ['unsubscribed address', 'spam reporting address'];

    private const UNSUBSCRIBE_EVENTS = ['spamreport', 'unsubscribe', 'group_unsubscribe'];

    private const COMMENT_MAX_LENGTH = 255;

    /**
     * @param array<string, mixed> $event
     *
     * @return array{reason: int, comment: string}|null
     */
    public function map(array $event, bool $processUnsubscribes): ?array
    {
        $type   = strtolower((string) ($event['event'] ?? ''));
        $reason = trim((string) ($event['reason'] ?? ''));

        if ('bounce' === $type) {
            // O SendGrid manda "blocked" no mesmo evento: é recusa temporária
            // (reputação, caixa cheia, greylisting) e não prova que o endereço não existe.
            if ('blocked' === strtolower((string) ($event['type'] ?? 'bounce'))) {
                return null;
            }

            return $this->result(DoNotContact::BOUNCED, 'bounce', $reason);
        }

        if ('dropped' === $type) {
            $normalized = strtolower($reason);

            if (in_array($normalized, self::DROPPED_BOUNCE_REASONS, true)) {
                return $this->result(DoNotContact::BOUNCED, 'dropped', $reason);
            }

            if ($processUnsubscribes && in_array($normalized, self::DROPPED_UNSUBSCRIBE_REASONS, true)) {
                return $this->result(DoNotContact::UNSUBSCRIBED, 'dropped', $reason);
            }

            return null;
        }

        if ($processUnsubscribes && in_array($type, self::UNSUBSCRIBE_EVENTS, true)) {
            return $this->result(DoNotContact::UNSUBSCRIBED, $type, $reason);
        }

        return null;
    }

    /**
     * @return array{reason: int, comment: string}
     */
    private function result(int $dncReason, string $type, string $reason): array
    {
        $comment = 'SendGrid '.$type.('' !== $reason ? ': '.$reason : '');

        return ['reason' => $dncReason, 'comment' => mb_substr($comment, 0, self::COMMENT_MAX_LENGTH)];
    }
}
