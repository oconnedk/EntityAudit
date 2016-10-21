<?php

/**
 * Created by PhpStorm.
 * User: doconnell
 * Date: 20/10/16
 * Time: 10:43
 */
namespace SimpleThings\EntityAudit\Tests;

use SimpleThings\EntityAudit\AuditReader;
use SimpleThings\EntityAudit\Tests\Fixtures\Relation\CheeseProduct;
use SimpleThings\EntityAudit\Tests\Fixtures\Relation\FoodCategory;

class PurgeTest extends BaseTest
{
    /**
     * @var AuditReader
     */
    protected $auditReader;

    protected $schemaEntities = array(
        'SimpleThings\EntityAudit\Tests\Fixtures\Relation\Category',
        'SimpleThings\EntityAudit\Tests\Fixtures\Relation\FoodCategory',
        'SimpleThings\EntityAudit\Tests\Fixtures\Relation\Product',
        'SimpleThings\EntityAudit\Tests\Fixtures\Relation\CheeseProduct',
    );

    protected $auditedEntities = array(
        'SimpleThings\EntityAudit\Tests\Fixtures\Relation\Category',
        'SimpleThings\EntityAudit\Tests\Fixtures\Relation\FoodCategory',
        'SimpleThings\EntityAudit\Tests\Fixtures\Relation\Product',
        'SimpleThings\EntityAudit\Tests\Fixtures\Relation\CheeseProduct',
    );

    /**
     * @return AuditReader
     */
    public function getAuditReader()
    {
        if (null !== $this->auditReader) {
            $this->auditReader->clearEntityCache();
            return $this->auditReader;
        }

        return $this->auditReader = $this->auditManager->createAuditReader($this->em);
    }

    /**
     * @dataProvider invalidRetentionPeriods 
     * @expectedException \SimpleThings\EntityAudit\Exception\ConfigurationException
     */
    public function testInvalidRetentionPeriod($period)
    {
        $config = $this->getAuditManager()->getConfiguration();
        $config->setRetentionPeriodMonths($period);
    }
    
    public function invalidRetentionPeriods()
    {
        return [
            [-1],
            ['a']
        ];
    }

    /**
     * @dataProvider validRetentionPeriods
     */
    public function testValidRetentionPeriod($period)
    {
        $config = $this->getAuditManager()->getConfiguration();
        $config->setRetentionPeriodMonths($period);
        $this->assertEquals($period, $config->getRetentionPeriodMonths(), 'Mis-match when retrieving retention period');
    }

    public function validRetentionPeriods()
    {
        return [
            [NULL],
            [0],
            [1],
            [1000],
        ];
    }

    public function testWithConfigRetentionPeriod()
    {
        $config = $this->getAuditManager()->getConfiguration();
        $retainFor = 3;
        $config->setRetentionPeriodMonths($retainFor);

        $this->createTestData($retainFor * 3);
        $this->createRevisionHistory();

        $purger = $this->getAuditPurger();
        $purger->purge();

        $this->assertNoRevisionsOlderThan($retainFor);
        $this->assertNoOrphans();
    }

    public function testWithOverrrideRetentionPeriod()
    {
        $purger = $this->getAuditPurger();
        $retainFor = 6;
        $this->createTestData($retainFor * 3);
        $this->createRevisionHistory();
        $purger->purge($retainFor);
        $this->assertNoRevisionsOlderThan($retainFor);
        $this->assertNoOrphans();
    }

    /**
     * @param $months
     */
    private function assertNoRevisionsOlderThan($months)
    {
        $purger = $this->getAuditPurger();
        $purgeBefore = $purger->getPurgeDate();
        $auditReader = $this->getAuditReader();
        foreach ($auditReader->findRevisionHistory(10000) as $revision) {
            $this->assertGreaterThanOrEqual(
                $purgeBefore,
                $revision->getTimestamp(),
                "There should not be any revisions older than $months months"
            );
        }
    }

    private function assertNoOrphans()
    {
        $revisionsTable = $this->getAuditManager()->getConfiguration()->getRevisionTableName();
        $revisionsField = $this->getAuditManager()->getConfiguration()->getRevisionFieldName();
        foreach ($this->auditedEntities as $audited) {
            $tableName = $this->getAuditManager()->getConfiguration()->getTableName($this->em->getClassMetadata($audited));
            $orphans = $this->em->getConnection()->fetchColumn(
                "SELECT COUNT(1) 
                 FROM $tableName a
                 WHERE NOT EXISTS 
                 (
                    SELECT 1
                    FROM $revisionsTable r 
                    WHERE r.id = a.$revisionsField
                 )"
            );
            $this->assertEquals(
                0,
                $orphans,
                "There are orphaned records within $tableName not associated with a $revisionsTable entry"
            );
        }
        return true;
    }

    /**
     * Cater for difference in dealing with timestamps in SQLite
     * @param \DateTime $date
     * @return string
     */
    private function makeDateString(\DateTime $date)
    {
        $str = "'".$date->format('Y-m-d')."'";
        return $this->getDBPlatform() == 'sqlite' ? "DateTime($str)" : $str;
    }

    /**
     * @return string
     */
    private function getDBPlatform()
    {
        static $dbPlatform = null;
        if (!$dbPlatform) {
            $dbPlatform = $this->em->getConnection()->getDatabasePlatform()->getName();
        }
        return $dbPlatform;
    }

    /**
     * Go through all revisions and manufacture revision dates going back a month per revision
     */
    private function createRevisionHistory()
    {
        $revisionsTable = $this->auditManager->getConfiguration()->getRevisionTableName();
        $revisions = $this->em->getConnection()->fetchAll("SELECT * FROM $revisionsTable ORDER BY id");
        $date = new \DateTime('first day of this month');

        foreach ($revisions as $revision) {
            $dateStr = $this->makeDateString($date);
            $this->em->getConnection()->executeUpdate(
                "UPDATE $revisionsTable 
                 SET timestamp = $dateStr
                 WHERE id = :id",
                [
                    'id' => $revision['id']
                ]
            );
            $date->sub(new \DateInterval('P1M'));
        }
    }

    /**
     * Create some simple data and
     * @param int $numberOfUpdates - number of updates to make to one of the entities, giving us a history
     */
    private function createTestData($numberOfUpdates = 1)
    {
        $food = new FoodCategory();
        $this->em->persist($food);

        $parmesanCheese = new CheeseProduct('Parmesan');
        $this->em->persist($parmesanCheese);

        $cheddarCheese = new CheeseProduct('Cheddar');
        $this->em->persist($cheddarCheese);

        $food->addProduct($parmesanCheese);
        $food->addProduct($cheddarCheese);

        $this->em->flush();
        $parmesanCheese->setName('Parmigiano');

        $this->em->persist($parmesanCheese);
        $this->em->flush();

        $this->em->remove($parmesanCheese);
        $this->em->flush();

        $cheesyName = $cheddarCheese->getName();
        for ($i = 0; $i < $numberOfUpdates; ++$i) {
            $cheddarCheese->setName($cheesyName ."-$i");
            $this->em->persist($cheddarCheese);
            $this->em->flush();
        }
    }
}
