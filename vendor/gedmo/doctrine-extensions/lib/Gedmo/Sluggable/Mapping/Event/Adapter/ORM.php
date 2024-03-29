<?php

namespace Gedmo\Sluggable\Mapping\Event\Adapter;

use Gedmo\Mapping\Event\Adapter\ORM as BaseAdapterORM;
use Doctrine\ORM\Query;
use Gedmo\Sluggable\Mapping\Event\SluggableAdapter;
use Gedmo\Tool\Wrapper\AbstractWrapper;

/**
 * Doctrine event adapter for ORM adapted
 * for sluggable behavior
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class ORM extends BaseAdapterORM implements SluggableAdapter
{
    /**
     * {@inheritDoc}
     */
    public function getSimilarSlugs($object, $meta, array $config, $slug)
    {
        $em = $this->getObjectManager();
        $wrapped = AbstractWrapper::wrap($object, $em);
        $qb = $em->createQueryBuilder();
        $qb->select('rec.'.$config['slug'])
            ->from($config['useObjectClass'], 'rec')
            ->where($qb->expr()->like(
                'rec.'.$config['slug'],
                ':slug')
            )
        ;
        $qb->setParameter('slug',$slug.'%');

        // use the unique_base to restrict the uniqueness check
        if ($config['unique'] && isset($config['unique_base'])) {
            if (($ubase = $wrapped->getPropertyValue($config['unique_base'])) && !array_key_exists($config['unique_base'], $wrapped->getMetadata()->getAssociationMappings())) {
                $qb->andWhere('rec.'.$config['unique_base'].' = :unique_base');
                $qb->setParameter(':unique_base', $ubase);
            } elseif (array_key_exists($config['unique_base'], $wrapped->getMetadata()->getAssociationMappings())) {
                $associationMappings = $wrapped->getMetadata()->getAssociationMappings();
                $qb->join($associationMappings[$config['unique_base']]['targetEntity'], 'unique_'.$config['unique_base']);
            } else {
                $qb->andWhere($qb->expr()->isNull('rec.'.$config['unique_base']));
            }
        }

        // include identifiers
        foreach ((array) $wrapped->getIdentifier(false) as $id => $value) {
            if (!$meta->isIdentifier($config['slug'])) {
                $qb->andWhere($qb->expr()->neq('rec.'.$id, ':'.$id));
                $qb->setParameter($id, $value);
            }
        }
        $q = $qb->getQuery();
        $q->setHydrationMode(Query::HYDRATE_ARRAY);

        return $q->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function replaceRelative($object, array $config, $target, $replacement)
    {
        $em = $this->getObjectManager();
        $qb = $em->createQueryBuilder();
        $qb->update($config['useObjectClass'], 'rec')
            ->set('rec.'.$config['slug'], $qb->expr()->concat(
                $qb->expr()->literal($replacement),
                $qb->expr()->substring('rec.'.$config['slug'], strlen($target))
            ))
            ->where($qb->expr()->like(
                'rec.'.$config['slug'],
                $qb->expr()->literal($target.'%'))
            )
        ;
        // update in memory
        $q = $qb->getQuery();

        return $q->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function replaceInverseRelative($object, array $config, $target, $replacement)
    {
        $em = $this->getObjectManager();
        $qb = $em->createQueryBuilder();
        $qb->update($config['useObjectClass'], 'rec')
            ->set('rec.'.$config['slug'], $qb->expr()->concat(
                $qb->expr()->literal($target),
                $qb->expr()->substring('rec.'.$config['slug'], strlen($replacement)+1)
            ))
            ->where('rec.'.$config['mappedBy'].' = :object')
        ;
        $q = $qb->getQuery();
        $q->setParameters(compact('object'));

        return $q->execute();
    }
}
