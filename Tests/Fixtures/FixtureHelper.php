<?php

declare(strict_types=1);

namespace MauticPlugin\HousekeepingBundle\Tests\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\FailedLeadEventLog;
use Mautic\CampaignBundle\Entity\LeadEventLog as CampaignLeadEventLog;
use Mautic\CoreBundle\Entity\IpAddress;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat as EmailStat;
use Mautic\EmailBundle\Entity\StatDevice;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Entity\ListLead;
use Mautic\PageBundle\Entity\Hit;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Entity\Plugin;
use MauticPlugin\HousekeepingBundle\Integration\HousekeepingIntegration;

final class FixtureHelper
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function enablePlugin(): void
    {
        $plugin = new Plugin();
        $plugin->setName('Mautic Housekeeping');
        $plugin->setBundle('HousekeepingBundle');
        $this->em->persist($plugin);

        $integration = new Integration();
        $integration->setPlugin($plugin);
        $integration->setIsPublished(true);
        $integration->setName(HousekeepingIntegration::INTEGRATION_NAME);
        $this->em->persist($integration);
        $this->em->flush();
    }

    public function createCampaign(string $name): Campaign
    {
        $campaign = new Campaign();
        $campaign->setName($name);
        $campaign->setIsPublished(true);
        $this->em->persist($campaign);
        $this->em->flush();

        return $campaign;
    }

    public function createCampaignEvent(
        Campaign $campaign,
        string $name = 'Test Event',
        string $type = 'lead.leadlist',
        string $eventType = 'action',
        ?Event $parent = null,
    ): Event {
        $event = new Event();
        $event->setCampaign($campaign);
        $event->setName($name);
        $event->setType($type);
        $event->setEventType($eventType);
        $event->setTriggerMode('immediate');
        if (null !== $parent) {
            $event->setParent($parent);
            $event->setDecisionPath('yes');
        }
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    public function createContact(string $email): Lead
    {
        $contact = new Lead();
        $contact->setEmail($email);
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    /**
     * Saves a contact with no email, never identified and never active unless dates are given.
     * Mautic saves a change to an entity only when it is persisted again, so the dates are set before the first save.
     *
     * @param \DateTime      $dateAdded      when the contact was added
     * @param \DateTime|null $dateIdentified when the contact was identified, or null for never
     * @param \DateTime|null $lastActive     when the contact was last active, or null for never
     *
     * @return Lead the saved contact
     */
    public function createAnonymousContact(\DateTime $dateAdded, ?\DateTime $dateIdentified = null, ?\DateTime $lastActive = null): Lead
    {
        $contact = new Lead();
        $contact->setDateAdded($dateAdded);
        $contact->setDateIdentified($dateIdentified);
        $contact->setLastActive($lastActive);
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    /**
     * Adds a contact to a segment, as a segment rebuild does.
     *
     * @param Lead     $contact the contact to add
     * @param LeadList $segment the segment to add it to
     */
    public function addContactToSegment(Lead $contact, LeadList $segment): void
    {
        $membership = new ListLead();
        $membership->setLead($contact);
        $membership->setList($segment);
        $membership->setDateAdded(new \DateTime());
        $this->em->persist($membership);
        $this->em->flush();
    }

    /**
     * Marks a campaign event of a contact as failed.
     *
     * @param CampaignLeadEventLog $log the campaign event that failed
     */
    public function createFailedCampaignEvent(CampaignLeadEventLog $log): void
    {
        $failed = new FailedLeadEventLog();
        $failed->setLog($log);
        $failed->setReason('Test failure');
        $failed->setDateAdded(new \DateTime());
        $this->em->persist($failed);
        $this->em->flush();
    }

    public function createCampaignLeadEventLog(
        Lead $lead,
        Campaign $campaign,
        Event $event,
        \DateTime $dateTriggered,
        int $rotation = 1,
    ): CampaignLeadEventLog {
        $log = new CampaignLeadEventLog();
        $log->setLead($lead);
        $log->setCampaign($campaign);
        $log->setEvent($event);
        $log->setDateTriggered($dateTriggered);
        $log->setRotation($rotation);
        $this->em->persist($log);
        $this->em->flush();

        return $log;
    }

    public function createLeadEventLog(Lead $lead, \DateTime $dateAdded, string $bundle = 'campaign', string $object = 'action'): LeadEventLog
    {
        $eventLog = new LeadEventLog();
        $eventLog->setLead($lead);
        $eventLog->setBundle($bundle);
        $eventLog->setObject($object);
        $eventLog->setAction('triggered');
        $eventLog->setObjectId(1);
        $eventLog->setDateAdded($dateAdded);
        $this->em->persist($eventLog);
        $this->em->flush();

        return $eventLog;
    }

    public function createEmail(string $name, bool $isPublished = true, ?\DateTime $publishDown = null): Email
    {
        $email = new Email();
        $email->setName($name);
        $email->setSubject($name);
        $email->setEmailType('template');
        $email->setIsPublished($isPublished);
        if (null !== $publishDown) {
            $email->setPublishDown($publishDown);
        }
        $this->em->persist($email);
        $this->em->flush();

        return $email;
    }

    /**
     * Saves a segment with these filters.
     *
     * @param string                     $alias   the segment's name and alias
     * @param list<array<string, mixed>> $filters the segment's filters, none by default
     *
     * @return LeadList the saved segment
     */
    public function createSegment(string $alias, array $filters = []): LeadList
    {
        $segment = new LeadList();
        $segment->setName($alias);
        $segment->setPublicName($alias);
        $segment->setAlias($alias);
        $segment->setFilters($filters);
        $this->em->persist($segment);
        $this->em->flush();

        return $segment;
    }

    /**
     * Saves a segment email sent to these segments, as an A/B variant or a translation of another email when one is given.
     *
     * @param string         $name          the email's name and subject
     * @param bool           $isPublished   whether the email is published
     * @param list<LeadList> $segments      the segments the email is sent to
     * @param Email|null     $variantOf     the email this one is an A/B variant of
     * @param Email|null     $translationOf the email this one is a translation of
     *
     * @return Email the saved email
     */
    public function createSegmentEmail(string $name, bool $isPublished, array $segments, ?Email $variantOf = null, ?Email $translationOf = null): Email
    {
        $email = new Email();
        $email->setName($name);
        $email->setSubject($name);
        $email->setEmailType('list');
        $email->setIsPublished($isPublished);

        foreach ($segments as $segment) {
            $email->addList($segment);
        }

        if (null !== $variantOf) {
            $email->setVariantParent($variantOf);
        }

        if (null !== $translationOf) {
            $email->setTranslationParent($translationOf);
        }

        $this->em->persist($email);
        $this->em->flush();

        return $email;
    }

    /**
     * @param array<string, mixed>|null $tokens
     */
    public function createEmailStat(
        Lead $lead,
        ?Email $email,
        \DateTime $dateSent,
        ?array $tokens = null,
    ): EmailStat {
        $stat = new EmailStat();
        $stat->setLead($lead);
        if (null !== $email) {
            $stat->setEmail($email);
        }
        $stat->setEmailAddress($lead->getEmail());
        $stat->setDateSent($dateSent);
        $stat->setTrackingHash(bin2hex(random_bytes(16)));
        if (null !== $tokens) {
            $stat->setTokens($tokens);
        }
        $this->em->persist($stat);
        $this->em->flush();

        return $stat;
    }

    public function createEmailStatDevice(EmailStat $stat, \DateTime $dateOpened): StatDevice
    {
        $ipAddress = $this->createIpAddress();

        $statDevice = new StatDevice();
        $statDevice->setStat($stat);
        $statDevice->setIpAddress($ipAddress);
        $statDevice->setDateOpened($dateOpened);
        $this->em->persist($statDevice);
        $this->em->flush();

        return $statDevice;
    }

    public function createPageHit(Lead $lead, \DateTime $dateHit, ?Email $email = null): Hit
    {
        $ipAddress = $this->createIpAddress();

        $hit = new Hit();
        $hit->setLead($lead);
        $hit->setIpAddress($ipAddress);
        $hit->setDateHit($dateHit);
        $hit->setCode(200);
        $hit->setUrl('https://example.com/test-page');
        $hit->setTrackingId(bin2hex(random_bytes(16)));
        if (null !== $email) {
            $hit->setEmail($email);
        }
        $this->em->persist($hit);
        $this->em->flush();

        return $hit;
    }

    public function daysAgo(int $days): \DateTime
    {
        return (new \DateTime())->modify("-{$days} days");
    }

    public function createIpAddress(string $ip = '127.0.0.1'): IpAddress
    {
        $ipAddress = new IpAddress($ip);
        $this->em->persist($ipAddress);
        $this->em->flush();

        return $ipAddress;
    }

    /**
     * Links an IP address to a contact, which Mautic stores in lead_ips_xref.
     *
     * @param Lead      $contact the contact that used the address
     * @param IpAddress $ip      the address to link
     */
    public function addIpAddressToContact(Lead $contact, IpAddress $ip): void
    {
        $contact->addIpAddress($ip);
        $this->em->persist($contact);
        $this->em->flush();
    }

    /**
     * Saves an IP address row with this ID, so a test can place it anywhere in the ID range.
     *
     * @param int    $id the ID the new row gets
     * @param string $ip the address the new row holds
     */
    public function createIpAddressWithId(int $id, string $ip = '192.0.2.1'): void
    {
        $this->em->getConnection()->insert(MAUTIC_TABLE_PREFIX.'ip_addresses', ['id' => $id, 'ip_address' => $ip, 'ip_details' => 'a:0:{}']);
    }
}
