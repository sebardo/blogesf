<?php

namespace Gedmo\Tree\Strategy\ORM;

use Gedmo\Exception\UnexpectedValueException;
use Doctrine\ORM\Proxy\Proxy;
use Gedmo\Tool\Wrapper\AbstractWrapper;
use Gedmo\Tree\Strategy;
use Doctrine\ORM\EntityManager;
use Gedmo\Tree\TreeListener;
use Gedmo\Mapping\Event\AdapterInterface;

/**
 * This strategy makes the tree act like a nested set.
 *
 * This behavior can impact the performance of your application
 * since nested set trees are slow on inserts and updates.
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class Nested implements Strategy
{
    /**
     * Previous sibling position
     */
    const PREV_SIBLING = 'PrevSibling';

    /**
     * Next sibling position
     */
    const NEXT_SIBLING = 'NextSibling';

    /**
     * Last child position
     */
    const LAST_CHILD = 'LastChild';

    /**
     * First child position
     */
    const FIRST_CHILD = 'FirstChild';

    /**
     * TreeListener
     *
     * @var TreeListener
     */
    protected $listener = null;

    /**
     * The max number of "right" field of the
     * tree in case few root nodes will be persisted
     * on one flush for node classes
     *
     * @var array
     */
    private $treeEdges = array();

    /**
     * Stores a list of node position strategies
     * for each node by object hash
     *
     * @var array
     */
    private $nodePositions = array();

    /**
     * Stores a list of delayed nodes for correct order of updates
     *
     * @var array
     */
    private $delayedNodes = array();

    /**
     * {@inheritdoc}
     */
    public function __construct(TreeListener $listener)
    {
        $this->listener = $listener;
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return Strategy::NESTED;
    }

    /**
     * Set node position strategy
     *
     * @param string $oid
     * @param string $position
     */
    public function setNodePosition($oid, $position)
    {
        $valid = array(
            self::FIRST_CHILD,
            self::LAST_CHILD,
            self::NEXT_SIBLING,
            self::PREV_SIBLING,
        );
        if (!in_array($position, $valid, false)) {
            throw new \Gedmo\Exception\InvalidArgumentException("Position: {$position} is not valid in nested set tree");
        }
        $this->nodePositions[$oid] = $position;
    }

    /**
     * {@inheritdoc}
     */
    public function processScheduledInsertion($em, $node, AdapterInterface $ea)
    {
        $meta = $em->getClassMetadata(get_class($node));
        $config = $this->listener->getConfiguration($em, $meta->name);

        $meta->getReflectionProperty($config['left'])->setValue($node, 0);
        $meta->getReflectionProperty($config['right'])->setValue($node, 0);
        if (isset($config['level'])) {
            $meta->getReflectionProperty($config['level'])->setValue($node, 0);
        }
        if (isset($config['root'])) {
            $meta->getReflectionProperty($config['root'])->setValue($node, 0);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function processScheduledUpdate($em, $node, AdapterInterface $ea)
    {
        $meta = $em->getClassMetadata(get_class($node));
        $config = $this->listener->getConfiguration($em, $meta->name);
        $uow = $em->getUnitOfWork();

        $changeSet = $uow->getEntityChangeSet($node);
        if (isset($config['root']) && isset($changeSet[$config['root']])) {
            throw new \Gedmo\Exception\UnexpectedValueException("Root cannot be changed manually, change parent instead");
        }

        $oid = spl_object_hash($node);
        if (isset($changeSet[$config['left']]) && isset($this->nodePositions[$oid])) {
            $wrapped = AbstractWrapper::wrap($node, $em);
            $parent = $wrapped->getPropertyValue($config['parent']);
            // revert simulated changeset
            $uow->clearEntityChangeSet($oid);
            $wrapped->setPropertyValue($config['left'], $changeSet[$config['left']][0]);
            $uow->setOriginalEntityProperty($oid, $config['left'], $changeSet[$config['left']][0]);
            // set back all other changes
            foreach ($changeSet as $field => $set) {
                if ($field !== $config['left']) {
                    if (is_array($set) && array_key_exists(0, $set) && array_key_exists(1, $set)) {
                        $uow->setOriginalEntityProperty($oid, $field, $set[0]);
                        $wrapped->setPropertyValue($field, $set[1]);
                    } else {
                        $uow->setOriginalEntityProperty($oid, $field, $set);
                        $wrapped->setPropertyValue($field, $set);
                    }
                }
            }
            $uow->recomputeSingleEntityChangeSet($meta, $node);
            $this->updateNode($em, $node, $parent);
        } elseif (isset($changeSet[$config['parent']])) {
            $this->updateNode($em, $node, $changeSet[$config['parent']][1]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function processPostPersist($em, $node, AdapterInterface $ea)
    {
        $meta = $em->getClassMetadata(get_class($node));
        $config = $this->listener->getConfiguration($em, $meta->name);
        $parent = $meta->getReflectionProperty($config['parent'])->getValue($node);
        $this->updateNode($em, $node, $parent, self::LAST_CHILD);
    }

    /**
     * {@inheritdoc}
     */
    public function processScheduledDelete($em, $node)
    {
        $meta = $em->getClassMetadata(get_class($node));
        $config = $this->listener->getConfiguration($em, $meta->name);
        $uow = $em->getUnitOfWork();

        $wrapped = AbstractWrapper::wrap($node, $em);
        $leftValue = $wrapped->getPropertyValue($config['left']);
        $rightValue = $wrapped->getPropertyValue($config['right']);

        if (!$leftValue || !$rightValue) {
            return;
        }
        $rootId = isset($config['root']) ? $wrapped->getPropertyValue($config['root']) : null;
        $diff = $rightValue - $leftValue + 1;
        if ($diff > 2) {
            $qb = $em->createQueryBuilder();
            $qb->select('node')
                ->from($config['useObjectClass'], 'node')
                ->where($qb->expr()->between('node.'.$config['left'], '?1', '?2'))
                ->setParameters(array(1 => $leftValue, 2 => $rightValue))
            ;

            if (isset($config['root'])) {
                $qb->andWhere($rootId === null ?
                    $qb->expr()->isNull('node.'.$config['root']) :
                    $qb->expr()->eq('node.'.$config['root'], is_string($rootId) ? $qb->expr()->literal($rootId) : $rootId)
                );
            }
            $q = $qb->getQuery();
            // get nodes for deletion
            $nodes = $q->getResult();
            foreach ((array) $nodes as $removalNode) {
                $uow->scheduleForDelete($removalNode);
            }
        }
        $this->shiftRL($em, $config['useObjectClass'], $rightValue + 1, -$diff, $rootId);
    }

    /**
     * {@inheritdoc}
     */
    public function onFlushEnd($em, AdapterInterface $ea)
    {
        // reset values
        $this->treeEdges = array();
    }

    /**
     * {@inheritdoc}
     */
    public function processPreRemove($em, $node)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function processPrePersist($em, $node)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function processPreUpdate($em, $node)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function processMetadataLoad($em, $meta)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function processPostUpdate($em, $entity, AdapterInterface $ea)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function processPostRemove($em, $entity, AdapterInterface $ea)
    {
    }

    /**
     * Update the $node with a diferent $parent
     * destination
     *
     * @param EntityManager $em
     * @param object        $node     - target node
     * @param object        $parent   - destination node
     * @param string        $position
     *
     * @throws \Gedmo\Exception\UnexpectedValueException
     */
    public function updateNode(EntityManager $em, $node, $parent, $position = 'FirstChild')
    {
        $wrapped = AbstractWrapper::wrap($node, $em);
        $meta = $wrapped->getMetadata();
        $config = $this->listener->getConfiguration($em, $meta->name);

        $rootId = isset($config['root']) ? $wrapped->getPropertyValue($config['root']) : null;
        $identifierField = $meta->getSingleIdentifierFieldName();
        $nodeId = $wrapped->getIdentifier();

        $left = $wrapped->getPropertyValue($config['left']);
        $right = $wrapped->getPropertyValue($config['right']);

        $isNewNode = empty($left) && empty($right);
        if ($isNewNode) {
            $left = 1;
            $right = 2;
        }

        $oid = spl_object_hash($node);
        if (isset($this->nodePositions[$oid])) {
            $position = $this->nodePositions[$oid];
        }
        $level = 0;
        $treeSize = $right - $left + 1;
        $newRootId = null;
        if ($parent) {
            $wrappedParent = AbstractWrapper::wrap($parent, $em);

            $parentRootId = isset($config['root']) ? $wrappedParent->getPropertyValue($config['root']) : null;
            $parentOid = spl_object_hash($parent);
            $parentLeft = $wrappedParent->getPropertyValue($config['left']);
            $parentRight = $wrappedParent->getPropertyValue($config['right']);
            if (empty($parentLeft) && empty($parentRight)) {
                // parent node is a new node, but wasn't processed yet (due to Doctrine commit order calculator redordering)
                // We delay processing of node to the moment parent node will be processed
                if (!isset($this->delayedNodes[$parentOid])) {
                    $this->delayedNodes[$parentOid] = array();
                }
                $this->delayedNodes[$parentOid][] = array('node' => $node, 'position' => $position);

                return;
            }
            if (!$isNewNode && $rootId === $parentRootId && $parentLeft >= $left && $parentRight <= $right) {
                throw new UnexpectedValueException("Cannot set child as parent to node: {$nodeId}");
            }
            if (isset($config['level'])) {
                $level = $wrappedParent->getPropertyValue($config['level']);
            }
            switch ($position) {
                case self::PREV_SIBLING:
                    if (property_exists($node, 'sibling')) {
                        $wrappedSibling = AbstractWrapper::wrap($node->sibling, $em);
                        $start = $wrappedSibling->getPropertyValue($config['left']);
                        $level++;
                    } else {
                        $newParent = $wrappedParent->getPropertyValue($config['parent']);
                        if (is_null($newParent) && (isset($config['root']) || $isNewNode)) {
                            throw new UnexpectedValueException("Cannot persist sibling for a root node, tree operation is not possible");
                        }
                        $wrapped->setPropertyValue($config['parent'], $newParent);
                        $em->getUnitOfWork()->recomputeSingleEntityChangeSet($meta, $node);
                        $start = $parentLeft;
                    }
                    break;

                case self::NEXT_SIBLING:
                    if (property_exists($node, 'sibling')) {
                        $wrappedSibling = AbstractWrapper::wrap($node->sibling, $em);
                        $start = $wrappedSibling->getPropertyValue($config['right']) + 1;
                        $level++;
                    } else {
                        $newParent = $wrappedParent->getPropertyValue($config['parent']);
                        if (is_null($newParent) && (isset($config['root']) || $isNewNode)) {
                            throw new UnexpectedValueException("Cannot persist sibling for a root node, tree operation is not possible");
                        }
                        $wrapped->setPropertyValue($config['parent'], $newParent);
                        $em->getUnitOfWork()->recomputeSingleEntityChangeSet($meta, $node);
                        $start = $parentRight + 1;
                    }
                    break;

                case self::LAST_CHILD:
                    $start = $parentRight;
                    $level++;
                    break;

                case self::FIRST_CHILD:
                default:
                    $start = $parentLeft + 1;
                    $level++;
                    break;
            }
            $this->shiftRL($em, $config['useObjectClass'], $start, $treeSize, $parentRootId);
            if (!$isNewNode && $rootId === $parentRootId && $left >= $start) {
                $left += $treeSize;
                $wrapped->setPropertyValue($config['left'], $left);
            }
            if (!$isNewNode && $rootId === $parentRootId && $right >= $start) {
                $right += $treeSize;
                $wrapped->setPropertyValue($config['right'], $right);
            }
            $newRootId = $parentRootId;
        } elseif (!isset($config['root'])) {
            $start = isset($this->treeEdges[$meta->name]) ?
                $this->treeEdges[$meta->name] : $this->max($em, $config['useObjectClass']);
            $this->treeEdges[$meta->name] = $start + 2;
            $start++;
        } else {
            $start = 1;
            $newRootId = $nodeId;
        }

        $diff = $start - $left;
        if (!$isNewNode) {
            $levelDiff = isset($config['level']) ? $level - $wrapped->getPropertyValue($config['level']) : null;
            $this->shiftRangeRL(
                $em,
                $config['useObjectClass'],
                $left,
                $right,
                $diff,
                $rootId,
                $newRootId,
                $levelDiff
            );
            $this->shiftRL($em, $config['useObjectClass'], $left, -$treeSize, $rootId);
        } else {
            $qb = $em->createQueryBuilder();
            $qb->update($config['useObjectClass'], 'node');
            if (isset($config['root'])) {
                $qb->set('node.'.$config['root'], null === $newRootId ?
                    'NULL' :
                    (is_string($newRootId) ? $qb->expr()->literal($newRootId) : $newRootId)
                );
                $wrapped->setPropertyValue($config['root'], $newRootId);
                $em->getUnitOfWork()->setOriginalEntityProperty($oid, $config['root'], $newRootId);
            }
            if (isset($config['level'])) {
                $qb->set('node.'.$config['level'], $level);
                $wrapped->setPropertyValue($config['level'], $level);
                $em->getUnitOfWork()->setOriginalEntityProperty($oid, $config['level'], $level);
            }
            if (isset($newParent)) {
                $wrappedNewParent = AbstractWrapper::wrap($newParent, $em);
                $newParentId = $wrappedNewParent->getIdentifier();
                $qb->set('node.'.$config['parent'], null === $newParentId ?
                    'NULL' :
                    (is_string($newParentId) ? $qb->expr()->literal($newParentId) : $newParentId)
                );
                $wrapped->setPropertyValue($config['parent'], $newParent);
                $em->getUnitOfWork()->setOriginalEntityProperty($oid, $config['parent'], $newParent);
            }
            $qb->set('node.'.$config['left'], $left + $diff);
            $qb->set('node.'.$config['right'], $right + $diff);
            // node id cannot be null
            $qb->where($qb->expr()->eq('node.'.$identifierField, is_string($nodeId) ? $qb->expr()->literal($nodeId) : $nodeId));
            $qb->getQuery()->getSingleScalarResult();
            $wrapped->setPropertyValue($config['left'], $left + $diff);
            $wrapped->setPropertyValue($config['right'], $right + $diff);
            $em->getUnitOfWork()->setOriginalEntityProperty($oid, $config['left'], $left + $diff);
            $em->getUnitOfWork()->setOriginalEntityProperty($oid, $config['right'], $right + $diff);
        }
        if (isset($this->delayedNodes[$oid])) {
            foreach ($this->delayedNodes[$oid] as $nodeData) {
                $this->updateNode($em, $nodeData['node'], $node, $nodeData['position']);
            }
        }
    }

    /**
     * Get the edge of tree
     *
     * @param EntityManager $em
     * @param string        $class
     * @param integer       $rootId
     *
     * @return integer
     */
    public function max(EntityManager $em, $class, $rootId = 0)
    {
        $meta = $em->getClassMetadata($class);
        $config = $this->listener->getConfiguration($em, $meta->name);
        $qb = $em->createQueryBuilder();
        $qb->select($qb->expr()->max('node.'.$config['right']))
            ->from($config['useObjectClass'], 'node')
        ;

        if (isset($config['root']) && $rootId) {
            $qb->where($rootId === null ?
                $qb->expr()->isNull('node.'.$config['root']) :
                $qb->expr()->eq('node.'.$config['root'], is_string($rootId) ? $qb->expr()->literal($rootId) : $rootId)
            );
        }
        $query = $qb->getQuery();
        $right = $query->getSingleScalarResult();

        return intval($right);
    }

    /**
     * Shift tree left and right values by delta
     *
     * @param EntityManager  $em
     * @param string         $class
     * @param integer        $first
     * @param integer        $delta
     * @param integer|string $rootId
     */
    public function shiftRL(EntityManager $em, $class, $first, $delta, $rootId = null)
    {
        $meta = $em->getClassMetadata($class);
        $config = $this->listener->getConfiguration($em, $class);

        $sign = ($delta >= 0) ? ' + ' : ' - ';
        $absDelta = abs($delta);
        $qb = $em->createQueryBuilder();
        $qb->update($config['useObjectClass'], 'node')
            ->set('node.'.$config['left'], "node.{$config['left']} {$sign} {$absDelta}")
            ->where($qb->expr()->gte('node.'.$config['left'], $first))
        ;
        if (isset($config['root'])) {
            $qb->andWhere($rootId === null ?
                $qb->expr()->isNull('node.'.$config['root']) :
                $qb->expr()->eq('node.'.$config['root'], is_string($rootId) ? $qb->expr()->literal($rootId) : $rootId)
            );
        }
        $qb->getQuery()->getSingleScalarResult();

        $qb = $em->createQueryBuilder();
        $qb->update($config['useObjectClass'], 'node')
            ->set('node.'.$config['right'], "node.{$config['right']} {$sign} {$absDelta}")
            ->where($qb->expr()->gte('node.'.$config['right'], $first))
        ;
        if (isset($config['root'])) {
            $qb->andWhere($rootId === null ?
                $qb->expr()->isNull('node.'.$config['root']) :
                $qb->expr()->eq('node.'.$config['root'], is_string($rootId) ? $qb->expr()->literal($rootId) : $rootId)
            );
        }

        $qb->getQuery()->getSingleScalarResult();
        // update in memory nodes increases performance, saves some IO
        foreach ($em->getUnitOfWork()->getIdentityMap() as $className => $nodes) {
            // for inheritance mapped classes, only root is always in the identity map
            if ($className !== $meta->rootEntityName) {
                continue;
            }
            foreach ($nodes as $node) {
                if ($node instanceof Proxy && !$node->__isInitialized__) {
                    continue;
                }
                $oid = spl_object_hash($node);
                $left = $meta->getReflectionProperty($config['left'])->getValue($node);
                $root = isset($config['root']) ? $meta->getReflectionProperty($config['root'])->getValue($node) : null;
                if ($root === $rootId && $left >= $first) {
                    $meta->getReflectionProperty($config['left'])->setValue($node, $left + $delta);
                    $em->getUnitOfWork()->setOriginalEntityProperty($oid, $config['left'], $left + $delta);
                }
                $right = $meta->getReflectionProperty($config['right'])->getValue($node);
                if ($root === $rootId && $right >= $first) {
                    $meta->getReflectionProperty($config['right'])->setValue($node, $right + $delta);
                    $em->getUnitOfWork()->setOriginalEntityProperty($oid, $config['right'], $right + $delta);
                }
            }
        }
    }

    /**
     * Shift range of right and left values on tree
     * depending on tree level difference also
     *
     * @param EntityManager  $em
     * @param string         $class
     * @param integer        $first
     * @param integer        $last
     * @param integer        $delta
     * @param integer|string $rootId
     * @param integer|string $destRootId
     * @param integer        $levelDelta
     */
    public function shiftRangeRL(EntityManager $em, $class, $first, $last, $delta, $rootId = null, $destRootId = null, $levelDelta = null)
    {
        $meta = $em->getClassMetadata($class);
        $config = $this->listener->getConfiguration($em, $class);

        $sign = ($delta >= 0) ? ' + ' : ' - ';
        $absDelta = abs($delta);
        $levelSign = ($levelDelta >= 0) ? ' + ' : ' - ';
        $absLevelDelta = abs($levelDelta);

        $qb = $em->createQueryBuilder();
        $qb->update($config['useObjectClass'], 'node')
            ->set('node.'.$config['left'], "node.{$config['left']} {$sign} {$absDelta}")
            ->set('node.'.$config['right'], "node.{$config['right']} {$sign} {$absDelta}")
            ->where($qb->expr()->gte('node.'.$config['left'], $first))
            ->andWhere($qb->expr()->lte('node.'.$config['right'], $last))
        ;
        if (isset($config['root'])) {
            $qb->set(
                'node.'.$config['root'],
                is_string($destRootId) ? $qb->expr()->literal($destRootId) : $destRootId
            );
            $qb->andWhere($rootId === null ?
                $qb->expr()->isNull('node.'.$config['root']) :
                $qb->expr()->eq('node.'.$config['root'], is_string($rootId) ? $qb->expr()->literal($rootId) : $rootId)
            );
        }
        if (isset($config['level'])) {
            $qb->set('node.'.$config['level'], "node.{$config['level']} {$levelSign} {$absLevelDelta}");
        }
        $qb->getQuery()->getSingleScalarResult();
        // update in memory nodes increases performance, saves some IO
        foreach ($em->getUnitOfWork()->getIdentityMap() as $className => $nodes) {
            // for inheritance mapped classes, only root is always in the identity map
            if ($className !== $meta->rootEntityName) {
                continue;
            }
            foreach ($nodes as $node) {
                if ($node instanceof Proxy && !$node->__isInitialized__) {
                    continue;
                }
                $left = $meta->getReflectionProperty($config['left'])->getValue($node);
                $right = $meta->getReflectionProperty($config['right'])->getValue($node);
                $root = isset($config['root']) ? $meta->getReflectionProperty($config['root'])->getValue($node) : null;
                if ($root === $rootId && $left >= $first && $right <= $last) {
                    $oid = spl_object_hash($node);
                    $uow = $em->getUnitOfWork();

                    $meta->getReflectionProperty($config['left'])->setValue($node, $left + $delta);
                    $uow->setOriginalEntityProperty($oid, $config['left'], $left + $delta);
                    $meta->getReflectionProperty($config['right'])->setValue($node, $right + $delta);
                    $uow->setOriginalEntityProperty($oid, $config['right'], $right + $delta);
                    if (isset($config['root'])) {
                        $meta->getReflectionProperty($config['root'])->setValue($node, $destRootId);
                        $uow->setOriginalEntityProperty($oid, $config['root'], $destRootId);
                    }
                    if (isset($config['level'])) {
                        $level = $meta->getReflectionProperty($config['level'])->getValue($node);
                        $meta->getReflectionProperty($config['level'])->setValue($node, $level + $levelDelta);
                        $uow->setOriginalEntityProperty($oid, $config['level'], $level + $levelDelta);
                    }
                }
            }
        }
    }
}
