<?php

namespace Gedmo\Sluggable\Mapping\Driver;

use Gedmo\Mapping\Driver\Xml as BaseXml;
use Gedmo\Exception\InvalidMappingException;

/**
 * This is a xml mapping driver for Sluggable
 * behavioral extension. Used for extraction of extended
 * metadata from xml specifically for Sluggable
 * extension.
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @author Miha Vrhovnik <miha.vrhovnik@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class Xml extends BaseXml
{
    /**
     * List of types which are valid for slug and sluggable fields
     *
     * @var array
     */
    private $validTypes = array(
        'string',
        'text',
        'integer',
        'int',
        'datetime',
    );

    /**
     * {@inheritDoc}
     */
    public function readExtendedMetadata($meta, array &$config)
    {
        /**
         * @var \SimpleXmlElement $xml
         */
        $xml = $this->_getMapping($meta->name);

        if (isset($xml->field)) {
            foreach ($xml->field as $mapping) {
                $mappingDoctrine = $mapping;
                /**
                 * @var \SimpleXmlElement $mapping
                 */
                $mapping = $mapping->children(self::GEDMO_NAMESPACE_URI);

                $field = $this->_getAttribute($mappingDoctrine, 'name');
                if (isset($mapping->slug)) {
                    /**
                     * @var \SimpleXmlElement $slug
                     */
                    $slug = $mapping->slug;
                    if (!$this->isValidField($meta, $field)) {
                        throw new InvalidMappingException("Cannot use field - [{$field}] for slug storage, type is not valid and must be 'string' in class - {$meta->name}");
                    }
                    $fields = array_map('trim', explode(',', (string) $this->_getAttribute($slug, 'fields')));
                    foreach ($fields as $slugField) {
                        if (!$meta->hasField($slugField)) {
                            throw new InvalidMappingException("Unable to find slug [{$slugField}] as mapped property in entity - {$meta->name}");
                        }
                        if (!$this->isValidField($meta, $slugField)) {
                            throw new InvalidMappingException("Cannot use field - [{$slugField}] for slug storage, type is not valid and must be 'string' or 'text' in class - {$meta->name}");
                        }
                    }

                    $handlers = array();
                    if (isset($slug->handler)) {
                        foreach ($slug->handler as $handler) {
                            $class = (string) $this->_getAttribute($handler, 'class');
                            $handlers[$class] = array();
                            foreach ($handler->{'handler-option'} as $option) {
                                $handlers[$class][(string) $this->_getAttribute($option, 'name')]
                                    = (string) $this->_getAttribute($option, 'value')
                                ;
                            }
                            $class::validate($handlers[$class], $meta);
                        }
                    }

                    // set all options
                    $config['slugs'][$field] = array(
                        'fields' => $fields,
                        'slug' => $field,
                        'style' => $this->_isAttributeSet($slug, 'style') ?
                            $this->_getAttribute($slug, 'style') : 'default',
                        'updatable' => $this->_isAttributeSet($slug, 'updatable') ?
                            $this->_getBooleanAttribute($slug, 'updatable') : true,
                        'dateFormat' => $this->_isAttributeSet($slug, 'dateFormat') ?
                            $this->_getAttribute($slug, 'dateFormat') : 'Y-m-d-H:i',
                        'unique' => $this->_isAttributeSet($slug, 'unique') ?
                            $this->_getBooleanAttribute($slug, 'unique') : true,
                        'unique_base' => $this->_isAttributeSet($slug, 'unique-base') ?
                            $this->_getAttribute($slug, 'unique-base') : null,
                        'separator' => $this->_isAttributeSet($slug, 'separator') ?
                            $this->_getAttribute($slug, 'separator') : '-',
                        'prefix' => $this->_isAttributeSet($slug, 'prefix') ?
                            $this->_getAttribute($slug, 'prefix') : '',
                        'suffix' => $this->_isAttributeSet($slug, 'suffix') ?
                            $this->_getAttribute($slug, 'suffix') : '',
                        'handlers' => $handlers,
                    );
                    if (!$meta->isMappedSuperclass && $meta->isIdentifier($field) && !$config['slugs'][$field]['unique']) {
                        throw new InvalidMappingException("Identifier field - [{$field}] slug must be unique in order to maintain primary key in class - {$meta->name}");
                    }
                    $ubase = $config['slugs'][$field]['unique_base'];
                    if ($config['slugs'][$field]['unique'] === false && $ubase) {
                        throw new InvalidMappingException("Slug annotation [unique_base] can not be set if unique is unset or 'false'");
                    }
                    if ($ubase && !$this->isValidField($meta, $ubase) && !$meta->hasAssociation($ubase)) {
                        throw new InvalidMappingException("Unable to find [{$ubase}] as mapped property in entity - {$meta->name}");
                    }
                }
            }
        }
    }

    /**
     * Checks if $field type is valid as Sluggable field
     *
     * @param object $meta
     * @param string $field
     *
     * @return boolean
     */
    protected function isValidField($meta, $field)
    {
        $mapping = $meta->getFieldMapping($field);

        return $mapping && in_array($mapping['type'], $this->validTypes);
    }
}
