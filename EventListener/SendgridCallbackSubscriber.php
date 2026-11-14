<?php

declare(strict_types=1);

namespace MauticPlugin\SendgridSmtpFeedbackBundle\EventListener;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use MauticPlugin\SendgridSmtpFeedbackBundle\Service\SendgridEventMapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trata, no /mailer/callback do próprio Mautic, o corpo do SendGrid Event Webhook.
 *
 * O Mautic 5 só dispara o evento ON_TRANSPORT_WEBHOOK nessa rota e devolve 404 se
 * nenhum transporte responder. Com envio por SMTP nenhum responde, então este
 * subscriber ocupa esse lugar: reconhece o corpo do SendGrid, confere o token e
 * marca Não Contatar pelo endereço de e-mail.
 */
class SendgridCallbackSubscriber implements EventSubscriberInterface
{
    public const TOKEN_HEADER = 'X-Mautic-Callback-Token';

    public const TOKEN_QUERY = 'token';

    public function __construct(
        private TransportCallback $transportCallback,
        private CoreParametersHelper $parameters,
        private SendgridEventMapper $mapper,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [EmailEvents::ON_TRANSPORT_WEBHOOK => 'onTransportWebhook'];
    }

    public function onTransportWebhook(TransportWebhookEvent $event): void
    {
        // Outro transporte já respondeu a este callback.
        if (null !== $event->getResponse()) {
            return;
        }

        $request = $event->getRequest();
        $events  = $this->extractSendgridEvents($request);

        // Não é corpo do SendGrid: deixa para outro transporte (ou para o 404 do core).
        if (null === $events) {
            return;
        }

        $expectedToken = (string) $this->parameters->get('sendgrid_callback_token', '');

        if ('' === $expectedToken) {
            $this->logger->error('SendGrid callback recusado: parâmetro sendgrid_callback_token não configurado.');
            $event->setResponse(new JsonResponse(['error' => 'sendgrid_callback_token not configured'], Response::HTTP_SERVICE_UNAVAILABLE));

            return;
        }

        if (!hash_equals($expectedToken, $this->givenToken($request))) {
            $this->logger->warning('SendGrid callback recusado: token inválido.', ['ip' => $request->getClientIp()]);
            $event->setResponse(new JsonResponse(['error' => 'invalid token'], Response::HTTP_UNAUTHORIZED));

            return;
        }

        $processUnsubscribes = (bool) $this->parameters->get('sendgrid_callback_process_unsubscribes', false);
        $processed           = 0;
        $ignored             = 0;
        $failed              = 0;

        foreach ($events as $sendgridEvent) {
            $action = $this->mapper->map($sendgridEvent, $processUnsubscribes);

            if (null === $action) {
                ++$ignored;
                continue;
            }

            try {
                $this->transportCallback->addFailureByAddress($sendgridEvent['email'], $action['comment'], $action['reason']);
                ++$processed;
            } catch (\Throwable $e) {
                ++$failed;
                $this->logger->error('SendGrid callback: falha ao marcar Não Contatar.', [
                    'sg_event_id' => $sendgridEvent['sg_event_id'] ?? null,
                    'exception'   => $e->getMessage(),
                ]);
            }
        }

        $event->setResponse(new JsonResponse([
            'processed' => $processed,
            'ignored'   => $ignored,
            'failed'    => $failed,
        ]));
    }

    /**
     * O corpo do SendGrid é uma lista de eventos, cada um com "event" e "email".
     * Pelo menos um precisa ter "sg_event_id", para não confundir com o corpo
     * de outro provedor que também use "event"/"email".
     *
     * @return list<array<string, mixed>>|null
     */
    private function extractSendgridEvents(Request $request): ?array
    {
        try {
            $body = json_decode((string) $request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($body) || [] === $body || !array_is_list($body)) {
            return null;
        }

        $hasSendgridId = false;

        foreach ($body as $item) {
            if (!is_array($item) || !isset($item['event'], $item['email']) || !is_string($item['event']) || !is_string($item['email'])) {
                return null;
            }

            $hasSendgridId = $hasSendgridId || isset($item['sg_event_id']);
        }

        return $hasSendgridId ? $body : null;
    }

    private function givenToken(Request $request): string
    {
        $header = $request->headers->get(self::TOKEN_HEADER);

        if (null !== $header && '' !== $header) {
            return $header;
        }

        return (string) $request->query->get(self::TOKEN_QUERY, '');
    }
}
