<?php

declare(strict_types=1);

namespace MauticPlugin\SendgridSmtpFeedbackBundle\Tests\Unit\Service;

use Mautic\LeadBundle\Entity\DoNotContact;
use MauticPlugin\SendgridSmtpFeedbackBundle\Service\SendgridEventMapper;
use PHPUnit\Framework\TestCase;

class SendgridEventMapperTest extends TestCase
{
    private SendgridEventMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new SendgridEventMapper();
    }

    public function testHardBounceIsBounced(): void
    {
        $result = $this->mapper->map(['event' => 'bounce', 'type' => 'bounce', 'reason' => '550 5.1.1 User unknown'], false);

        $this->assertSame(['reason' => DoNotContact::BOUNCED, 'comment' => 'SendGrid bounce: 550 5.1.1 User unknown'], $result);
    }

    public function testBounceWithoutTypeIsBounced(): void
    {
        $result = $this->mapper->map(['event' => 'bounce', 'reason' => 'x'], false);

        $this->assertSame(DoNotContact::BOUNCED, $result['reason'] ?? null);
    }

    public function testBlockedIsIgnored(): void
    {
        $this->assertNull($this->mapper->map(['event' => 'bounce', 'type' => 'blocked', 'reason' => '421 try later'], true));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function droppedBounceReasons(): array
    {
        return ['bounced address' => ['Bounced Address'], 'invalid' => ['Invalid']];
    }

    /**
     * @dataProvider droppedBounceReasons
     */
    public function testDroppedForBadAddressIsBounced(string $reason): void
    {
        $result = $this->mapper->map(['event' => 'dropped', 'reason' => $reason], false);

        $this->assertSame(['reason' => DoNotContact::BOUNCED, 'comment' => 'SendGrid dropped: '.$reason], $result);
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function unsubscribeEvents(): array
    {
        return [
            'spamreport'                => [['event' => 'spamreport']],
            'unsubscribe'               => [['event' => 'unsubscribe']],
            'group_unsubscribe'         => [['event' => 'group_unsubscribe']],
            'dropped unsubscribed'      => [['event' => 'dropped', 'reason' => 'Unsubscribed Address']],
            'dropped spam reporting'    => [['event' => 'dropped', 'reason' => 'Spam Reporting Address']],
        ];
    }

    /**
     * @dataProvider unsubscribeEvents
     *
     * @param array<string, string> $event
     */
    public function testUnsubscribeEventsAreIgnoredWhenDisabled(array $event): void
    {
        $this->assertNull($this->mapper->map($event, false));
    }

    /**
     * @dataProvider unsubscribeEvents
     *
     * @param array<string, string> $event
     */
    public function testUnsubscribeEventsAreUnsubscribedWhenEnabled(array $event): void
    {
        $this->assertSame(DoNotContact::UNSUBSCRIBED, $this->mapper->map($event, true)['reason'] ?? null);
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function neutralEvents(): array
    {
        return [
            'delivered'         => [['event' => 'delivered']],
            'open'              => [['event' => 'open']],
            'click'             => [['event' => 'click']],
            'deferred'          => [['event' => 'deferred']],
            'processed'         => [['event' => 'processed']],
            'dropped other'     => [['event' => 'dropped', 'reason' => 'Invalid SMTPAPI header']],
            'missing event'     => [[]],
        ];
    }

    /**
     * @dataProvider neutralEvents
     *
     * @param array<string, string> $event
     */
    public function testNeutralEventsAreIgnored(array $event): void
    {
        $this->assertNull($this->mapper->map($event, true));
    }

    public function testCommentIsTruncated(): void
    {
        $result = $this->mapper->map(['event' => 'bounce', 'reason' => str_repeat('x', 400)], false);

        $this->assertSame(255, mb_strlen($result['comment'] ?? ''));
    }
}
