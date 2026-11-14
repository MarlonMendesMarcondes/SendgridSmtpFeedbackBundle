<?php

declare(strict_types=1);

namespace MauticPlugin\SendgridSmtpFeedbackBundle\Tests\Unit\EventListener;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact;
use MauticPlugin\SendgridSmtpFeedbackBundle\EventListener\SendgridCallbackSubscriber;
use MauticPlugin\SendgridSmtpFeedbackBundle\Service\SendgridEventMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class SendgridCallbackSubscriberTest extends TestCase
{
    private const TOKEN = 's3cr3t-token';

    /**
     * @var TransportCallback&MockObject
     */
    private TransportCallback $transportCallback;

    /**
     * @var CoreParametersHelper&MockObject
     */
    private CoreParametersHelper $parameters;

    /**
     * @var array<string, mixed>
     */
    private array $config = ['sendgrid_callback_token' => self::TOKEN, 'sendgrid_callback_process_unsubscribes' => false];

    protected function setUp(): void
    {
        $this->transportCallback = $this->createMock(TransportCallback::class);
        $this->parameters        = $this->createMock(CoreParametersHelper::class);
        $this->parameters->method('get')->willReturnCallback(fn (string $name, $default = null) => $this->config[$name] ?? $default);
    }

    /**
     * @param array<string, string> $headers
     */
    private function dispatch(string $body, array $headers = ['X-Mautic-Callback-Token' => self::TOKEN], string $query = ''): TransportWebhookEvent
    {
        $request = Request::create('/mailer/callback'.$query, 'POST', [], [], [], [], $body);

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $event      = new TransportWebhookEvent($request);
        $subscriber = new SendgridCallbackSubscriber($this->transportCallback, $this->parameters, new SendgridEventMapper(), $this->createMock(LoggerInterface::class));
        $subscriber->onTransportWebhook($event);

        return $event;
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    private function json(array $events): string
    {
        return (string) json_encode($events);
    }

    /**
     * @return array<string, mixed>
     */
    private function bodyOf(TransportWebhookEvent $event): array
    {
        return (array) json_decode((string) $event->getResponse()?->getContent(), true);
    }

    public function testItListensToTheTransportWebhookEvent(): void
    {
        $this->assertSame([EmailEvents::ON_TRANSPORT_WEBHOOK => 'onTransportWebhook'], SendgridCallbackSubscriber::getSubscribedEvents());
    }

    public function testBounceMarksContactAsBounced(): void
    {
        $this->transportCallback->expects($this->once())
            ->method('addFailureByAddress')
            ->with('aluno@exemplo.com', 'SendGrid dropped: Bounced Address', DoNotContact::BOUNCED);

        $event = $this->dispatch($this->json([
            ['email' => 'aluno@exemplo.com', 'event' => 'dropped', 'reason' => 'Bounced Address', 'sg_event_id' => 'abc'],
        ]));

        $this->assertSame(Response::HTTP_OK, $event->getResponse()?->getStatusCode());
        $this->assertSame(['processed' => 1, 'ignored' => 0, 'failed' => 0], $this->bodyOf($event));
    }

    public function testIgnoredEventsAreCountedAndNotMarked(): void
    {
        $this->transportCallback->expects($this->once())->method('addFailureByAddress');

        $event = $this->dispatch($this->json([
            ['email' => 'a@exemplo.com', 'event' => 'bounce', 'type' => 'bounce', 'reason' => 'User unknown', 'sg_event_id' => '1'],
            ['email' => 'b@exemplo.com', 'event' => 'delivered', 'sg_event_id' => '2'],
            ['email' => 'c@exemplo.com', 'event' => 'unsubscribe', 'sg_event_id' => '3'],
        ]));

        $this->assertSame(['processed' => 1, 'ignored' => 2, 'failed' => 0], $this->bodyOf($event));
    }

    public function testUnsubscribesAreProcessedWhenEnabled(): void
    {
        $this->config['sendgrid_callback_process_unsubscribes'] = true;

        $this->transportCallback->expects($this->once())
            ->method('addFailureByAddress')
            ->with('c@exemplo.com', 'SendGrid unsubscribe', DoNotContact::UNSUBSCRIBED);

        $this->dispatch($this->json([['email' => 'c@exemplo.com', 'event' => 'unsubscribe', 'sg_event_id' => '3']]));
    }

    public function testTokenIsAcceptedFromTheQueryString(): void
    {
        $this->transportCallback->expects($this->once())->method('addFailureByAddress');

        $event = $this->dispatch(
            $this->json([['email' => 'a@exemplo.com', 'event' => 'bounce', 'sg_event_id' => '1']]),
            [],
            '?token='.self::TOKEN
        );

        $this->assertSame(Response::HTTP_OK, $event->getResponse()?->getStatusCode());
    }

    public function testWrongTokenIsRejected(): void
    {
        $this->transportCallback->expects($this->never())->method('addFailureByAddress');

        $event = $this->dispatch(
            $this->json([['email' => 'a@exemplo.com', 'event' => 'bounce', 'sg_event_id' => '1']]),
            ['X-Mautic-Callback-Token' => 'errado']
        );

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $event->getResponse()?->getStatusCode());
    }

    public function testMissingTokenIsRejected(): void
    {
        $this->transportCallback->expects($this->never())->method('addFailureByAddress');

        $event = $this->dispatch($this->json([['email' => 'a@exemplo.com', 'event' => 'bounce', 'sg_event_id' => '1']]), []);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $event->getResponse()?->getStatusCode());
    }

    public function testUnconfiguredTokenRefusesEverything(): void
    {
        $this->config['sendgrid_callback_token'] = '';
        $this->transportCallback->expects($this->never())->method('addFailureByAddress');

        $event = $this->dispatch($this->json([['email' => 'a@exemplo.com', 'event' => 'bounce', 'sg_event_id' => '1']]), ['X-Mautic-Callback-Token' => '']);

        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $event->getResponse()?->getStatusCode());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notSendgridBodies(): array
    {
        return [
            'empty'             => [''],
            'not json'          => ['event=bounce'],
            'empty list'        => ['[]'],
            'object not list'   => ['{"Type":"Notification","Message":"{}"}'],
            'no sg_event_id'    => ['[{"email":"a@exemplo.com","event":"bounce"}]'],
            'item without mail' => ['[{"event":"bounce","sg_event_id":"1"}]'],
        ];
    }

    /**
     * @dataProvider notSendgridBodies
     */
    public function testOtherBodiesAreLeftForOtherTransports(string $body): void
    {
        $this->transportCallback->expects($this->never())->method('addFailureByAddress');

        $this->assertNull($this->dispatch($body)->getResponse());
    }

    public function testAResponseFromAnotherTransportIsKept(): void
    {
        $request  = Request::create('/mailer/callback', 'POST', [], [], [], [], $this->json([['email' => 'a@exemplo.com', 'event' => 'bounce', 'sg_event_id' => '1']]));
        $event    = new TransportWebhookEvent($request);
        $existing = new Response('handled elsewhere');
        $event->setResponse($existing);

        $this->transportCallback->expects($this->never())->method('addFailureByAddress');
        (new SendgridCallbackSubscriber($this->transportCallback, $this->parameters, new SendgridEventMapper(), $this->createMock(LoggerInterface::class)))->onTransportWebhook($event);

        $this->assertSame($existing, $event->getResponse());
    }

    public function testAFailingEventDoesNotStopTheOthers(): void
    {
        $this->transportCallback->expects($this->exactly(2))
            ->method('addFailureByAddress')
            ->willReturnCallback(function (string $address): void {
                if ('a@exemplo.com' === $address) {
                    throw new \RuntimeException('db down');
                }
            });

        $event = $this->dispatch($this->json([
            ['email' => 'a@exemplo.com', 'event' => 'bounce', 'sg_event_id' => '1'],
            ['email' => 'b@exemplo.com', 'event' => 'bounce', 'sg_event_id' => '2'],
        ]));

        $this->assertSame(['processed' => 1, 'ignored' => 0, 'failed' => 1], $this->bodyOf($event));
    }
}
