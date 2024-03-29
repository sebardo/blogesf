<?php

namespace Gedmo\Sortable;

use Tool\BaseTestCaseMongoODM;
use Doctrine\Common\EventManager;
use Sortable\Fixture\Document\Article;

/**
 * These are tests for sortable behavior
 *
 * @author http://github.com/vetalt
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class SortableDocumentTest extends BaseTestCaseMongoODM
{
    const ARTICLE = 'Sortable\\Fixture\\Document\\Article';

    protected function setUp()
    {
        parent::setUp();
        $evm = new EventManager();
        $evm->addEventSubscriber(new SortableListener());

        $this->getMockDocumentManager($evm);
        $this->populate();
    }

    private function populate()
    {
        for ($i = 0; $i <= 4; $i++) {
            $article = new Article();
            $article->setTitle('article'.$i);
            $this->dm->persist($article);
        }
        $this->dm->flush();
        $this->dm->clear();
    }

    public function testInitialPositions()
    {
        $repo = $this->dm->getRepository(self::ARTICLE);
        for ($i = 0; $i <= 4; $i++) {
            $article = $repo->findOneByPosition($i);
            $this->assertEquals('article'.$i, $article->getTitle());
        }
    }

    public function testMovePositions()
    {
        $repo = $this->dm->getRepository(self::ARTICLE);

        $article = $repo->findOneByPosition(4);
        $article->setPosition(0);
        $this->dm->flush();

        for ($i = 1; $i <= 4; $i++) {
            $article = $repo->findOneByPosition($i);
            $this->assertEquals('article'.($i-1), $article->getTitle());
        }
    }

    public function testDeletePositions()
    {
        $repo = $this->dm->getRepository(self::ARTICLE);

        $article = $repo->findOneByPosition(0);
        $this->dm->remove($article);
        $this->dm->flush();

        for ($i = 0; $i <= 3; $i++) {
            $article = $repo->findOneByPosition($i);
            $this->assertEquals('article'.($i+1), $article->getTitle());
        }
    }
}
