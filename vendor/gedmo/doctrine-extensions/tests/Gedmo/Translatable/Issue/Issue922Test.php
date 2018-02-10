<?php

namespace Gedmo\Translatable;

use Doctrine\Common\EventManager;
use Tool\BaseTestCaseORM;
use Translatable\Fixture\Issue922\Post;
use Doctrine\ORM\Query;
use Gedmo\Translatable\Query\TreeWalker\TranslationWalker;

class Issue922Test extends BaseTestCaseORM
{
    const POST = 'Translatable\Fixture\Issue922\Post';
    const TRANSLATION = 'Gedmo\Translatable\Entity\Translation';

    const TREE_WALKER_TRANSLATION = 'Gedmo\\Translatable\\Query\\TreeWalker\\TranslationWalker';

    private $translatableListener;

    protected function setUp()
    {
        parent::setUp();

        $evm = new EventManager();
        $this->translatableListener = new TranslatableListener();
        $this->translatableListener->setTranslatableLocale('en');
        $this->translatableListener->setDefaultLocale('en');
        $this->translatableListener->setPersistDefaultLocaleTranslation(true);
        $evm->addEventSubscriber($this->translatableListener);

        $this->getMockSqliteEntityManager($evm);
    }

    /**
     * @test
     */
    public function shouldTranslateDateFields()
    {
        $p1 = new Post();
        $p1->setPublishedAt(new \DateTime());
        $p1->setTimestampAt(new \DateTime());
        $p1->setDateAt(new \DateTime());
        $p1->setBoolean(true);

        $this->em->persist($p1);
        $this->em->flush();

        $this->translatableListener->setTranslatableLocale('de');
        $p1->setBoolean(false);

        $this->em->persist($p1);
        $this->em->flush();

        // clear and test postLoad event values set
        $this->em->clear();

        $p1 = $this->em->find(self::POST, $p1->getId());
        $this->assertInstanceOf('DateTime', $p1->getPublishedAt());
        $this->assertInstanceOf('DateTime', $p1->getTimestampAt());
        $this->assertInstanceOf('DateTime', $p1->getDateAt());
        $this->assertSame(false, $p1->getBoolean());

        // clear and test query hint hydration
        $this->em->clear();
        $this->em
            ->getConfiguration()
            ->expects($this->any())
            ->method('getCustomHydrationMode')
            ->with(TranslationWalker::HYDRATE_OBJECT_TRANSLATION)
            ->will($this->returnValue('Gedmo\Translatable\Hydrator\ORM\ObjectHydrator'));

        $q = $this->em->createQuery('SELECT p FROM '.self::POST.' p');
        $q->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, self::TREE_WALKER_TRANSLATION);
        $q->setHint(TranslatableListener::HINT_TRANSLATABLE_LOCALE, 'de');

        $p1 = $q->getSingleResult();
        $this->assertInstanceOf('DateTime', $p1->getPublishedAt());
        $this->assertInstanceOf('DateTime', $p1->getTimestampAt());
        $this->assertInstanceOf('DateTime', $p1->getDateAt());
        $this->assertSame(false, $p1->getBoolean());
    }

    protected function getUsedEntityFixtures()
    {
        return array(
            self::POST,
            self::TRANSLATION,
        );
    }
}
