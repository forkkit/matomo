<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\ArchiveProcessor;

use Piwik\Concurrency\Lock;
use Piwik\Concurrency\LockBackend;
use Piwik\Container\StaticContainer;
use Piwik\SettingsPiwik;

class ArchivingStatus
{
    const LOCK_KEY_PREFIX = 'Archiving';
    const DEFAULT_ARCHIVING_TTL = 7200; // 2 hours

    /**
     * @var LockBackend
     */
    private $lockBackend;

    /**
     * @var int
     */
    private $archivingTTLSecs;

    public function __construct(LockBackend $lockBackend, $archivingTTLSecs = self::DEFAULT_ARCHIVING_TTL)
    {
        $this->lockBackend = $lockBackend;
        $this->archivingTTLSecs = $archivingTTLSecs;
    }

    public function archiveStarted(Parameters $params)
    {
        $lock = $this->makeArchivingLock($params);
        $lock->acquireLock($this->getInstanceProcessId(), $this->archivingTTLSecs);
        return $lock;
    }

    public function archiveFinished(Lock $lock)
    {
        $lock->unlock();
    }

    public function getSitesCurrentlyArchiving()
    {
        $lockMeta = new Lock($this->lockBackend, self::LOCK_KEY_PREFIX . '.');
        $acquiredLocks = $lockMeta->getAllAcquiredLockKeys();

        $sitesCurrentlyArchiving = [];
        foreach ($acquiredLocks as $lockKey) {
            $parts = explode('.', $lockKey);
            if (!isset($parts[1])) {
                continue;
            }
            $sitesCurrentlyArchiving[] = (int) $parts[1];
        }
        $sitesCurrentlyArchiving = array_unique($sitesCurrentlyArchiving);
        $sitesCurrentlyArchiving = array_values($sitesCurrentlyArchiving);

        return $sitesCurrentlyArchiving;
    }

    /**
     * @return Lock
     */
    private function makeArchivingLock(Parameters $params)
    {
        $doneFlag = Rules::getDoneStringFlagFor([$params->getSite()->getId()], $params->getSegment(),
            $params->getPeriod()->getLabel(), $params->getRequestedPlugin());

        $lockKeyParts = [
            self::LOCK_KEY_PREFIX,
            $params->getSite()->getId(),

            // md5 to keep it within the 70 char limit in the table
            md5($params->getPeriod()->getId() . $params->getPeriod()->getRangeString() . $doneFlag),
        ];

        $lockKeyPrefix = implode('.', $lockKeyParts);
        return new Lock(StaticContainer::get(LockBackend::class), $lockKeyPrefix);
    }

    private function getInstanceProcessId()
    {
        return SettingsPiwik::getPiwikInstanceId() . '.' . getmypid();
    }
}