<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Serializer\Tests\Normalizer;

use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Tests\Fixtures\CircularReferenceDummy;
use Symfony\Component\Serializer\Tests\Fixtures\SiblingHolder;

class GetSetMethodNormalizerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var GetSetMethodNormalizer
     */
    private $normalizer;

    protected function setUp()
    {
        $this->serializer = $this->getMock(__NAMESPACE__.'\SerializerNormalizer');
        $this->normalizer = new GetSetMethodNormalizer();
        $this->normalizer->setSerializer($this->serializer);
    }

    public function testNormalize()
    {
        $obj = new GetSetDummy();
        $object = new \stdClass();
        $obj->setFoo('foo');
        $obj->setBar('bar');
        $obj->setBaz(true);
        $obj->setCamelCase('camelcase');
        $obj->setObject($object);

        $this->serializer
            ->expects($this->once())
            ->method('normalize')
            ->with($object, 'any')
            ->will($this->returnValue('string_object'))
        ;

        $this->assertEquals(
            array(
                'foo' => 'foo',
                'bar' => 'bar',
                'baz' => true,
                'fooBar' => 'foobar',
                'camelCase' => 'camelcase',
                'object' => 'string_object',
            ),
            $this->normalizer->normalize($obj, 'any')
        );
    }

    public function testDenormalize()
    {
        $obj = $this->normalizer->denormalize(
            array('foo' => 'foo', 'bar' => 'bar', 'baz' => true, 'fooBar' => 'foobar'),
            __NAMESPACE__.'\GetSetDummy',
            'any'
        );
        $this->assertEquals('foo', $obj->getFoo());
        $this->assertEquals('bar', $obj->getBar());
        $this->assertTrue($obj->isBaz());
    }

    public function testDenormalizeWithObject()
    {
        $data = new \stdClass();
        $data->foo = 'foo';
        $data->bar = 'bar';
        $data->fooBar = 'foobar';
        $obj = $this->normalizer->denormalize($data, __NAMESPACE__.'\GetSetDummy', 'any');
        $this->assertEquals('foo', $obj->getFoo());
        $this->assertEquals('bar', $obj->getBar());
    }

    public function testDenormalizeOnCamelCaseFormat()
    {
        $this->normalizer->setCamelizedAttributes(array('camel_case'));
        $obj = $this->normalizer->denormalize(
            array('camel_case' => 'camelCase'),
            __NAMESPACE__.'\GetSetDummy'
        );
        $this->assertEquals('camelCase', $obj->getCamelCase());
    }

    public function testDenormalizeNull()
    {
        $this->assertEquals(new GetSetDummy(), $this->normalizer->denormalize(null, __NAMESPACE__.'\GetSetDummy'));
    }

    /**
     * @dataProvider attributeProvider
     */
    public function testFormatAttribute($attribute, $camelizedAttributes, $result)
    {
        $r = new \ReflectionObject($this->normalizer);
        $m = $r->getMethod('formatAttribute');
        $m->setAccessible(true);

        $this->normalizer->setCamelizedAttributes($camelizedAttributes);
        $this->assertEquals($m->invoke($this->normalizer, $attribute, $camelizedAttributes), $result);
    }

    public function attributeProvider()
    {
        return array(
            array('attribute_test', array('attribute_test'),'AttributeTest'),
            array('attribute_test', array('any'),'attribute_test'),
            array('attribute', array('attribute'),'Attribute'),
            array('attribute', array(), 'attribute'),
        );
    }

    public function testConstructorDenormalize()
    {
        $obj = $this->normalizer->denormalize(
            array('foo' => 'foo', 'bar' => 'bar', 'baz' => true, 'fooBar' => 'foobar'),
            __NAMESPACE__.'\GetConstructorDummy', 'any');
        $this->assertEquals('foo', $obj->getFoo());
        $this->assertEquals('bar', $obj->getBar());
        $this->assertTrue($obj->isBaz());
    }

    public function testConstructorDenormalizeWithMissingOptionalArgument()
    {
        $obj = $this->normalizer->denormalize(
            array('foo' => 'test', 'baz' => array(1, 2, 3)),
            __NAMESPACE__.'\GetConstructorOptionalArgsDummy', 'any');
        $this->assertEquals('test', $obj->getFoo());
        $this->assertEquals(array(), $obj->getBar());
        $this->assertEquals(array(1, 2, 3), $obj->getBaz());
    }

    public function testConstructorWithObjectDenormalize()
    {
        $data = new \stdClass();
        $data->foo = 'foo';
        $data->bar = 'bar';
        $data->baz = true;
        $data->fooBar = 'foobar';
        $obj = $this->normalizer->denormalize($data, __NAMESPACE__.'\GetConstructorDummy', 'any');
        $this->assertEquals('foo', $obj->getFoo());
        $this->assertEquals('bar', $obj->getBar());
    }

    /**
     * @dataProvider provideCallbacks
     */
    public function testCallbacks($callbacks, $value, $result, $message)
    {
        $this->normalizer->setCallbacks($callbacks);

        $obj = new GetConstructorDummy('', $value, true);

        $this->assertEquals(
            $result,
            $this->normalizer->normalize($obj, 'any'),
            $message
        );
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testUncallableCallbacks()
    {
        $this->normalizer->setCallbacks(array('bar' => null));

        $obj = new GetConstructorDummy('baz', 'quux', true);

        $this->normalizer->normalize($obj, 'any');
    }

    public function testIgnoredAttributes()
    {
        $this->normalizer->setIgnoredAttributes(array('foo', 'bar', 'baz', 'camelCase', 'object'));

        $obj = new GetSetDummy();
        $obj->setFoo('foo');
        $obj->setBar('bar');
        $obj->setBaz(true);

        $this->assertEquals(
            array('fooBar' => 'foobar'),
            $this->normalizer->normalize($obj, 'any')
        );
    }

    public function provideCallbacks()
    {
        return array(
            array(
                array(
                    'bar' => function ($bar) {
                        return 'baz';
                    },
                ),
                'baz',
                array('foo' => '', 'bar' => 'baz', 'baz' => true),
                'Change a string',
            ),
            array(
                array(
                    'bar' => function ($bar) {
                        return;
                    },
                ),
                'baz',
                array('foo' => '', 'bar' => null, 'baz' => true),
                'Null an item',
            ),
            array(
                array(
                    'bar' => function ($bar) {
                        return $bar->format('d-m-Y H:i:s');
                    },
                ),
                new \DateTime('2011-09-10 06:30:00'),
                array('foo' => '', 'bar' => '10-09-2011 06:30:00', 'baz' => true),
                'Format a date',
            ),
            array(
                array(
                    'bar' => function ($bars) {
                        $foos = '';
                        foreach ($bars as $bar) {
                            $foos .= $bar->getFoo();
                        }

                        return $foos;
                    },
                ),
                array(new GetConstructorDummy('baz', '', false), new GetConstructorDummy('quux', '', false)),
                array('foo' => '', 'bar' => 'bazquux', 'baz' => true),
                'Collect a property',
            ),
            array(
                array(
                    'bar' => function ($bars) {
                        return count($bars);
                    },
                ),
                array(new GetConstructorDummy('baz', '', false), new GetConstructorDummy('quux', '', false)),
                array('foo' => '', 'bar' => 2, 'baz' => true),
                'Count a property',
            ),
        );
    }

    /**
     * @expectedException \Symfony\Component\Serializer\Exception\LogicException
     * @expectedExceptionMessage Cannot normalize attribute "object" because injected serializer is not a normalizer
     */
    public function testUnableToNormalizeObjectAttribute()
    {
        $serializer = $this->getMock('Symfony\Component\Serializer\SerializerInterface');
        $this->normalizer->setSerializer($serializer);

        $obj = new GetSetDummy();
        $object = new \stdClass();
        $obj->setObject($object);

        $this->normalizer->normalize($obj, 'any');
    }

    /**
     * @expectedException \Symfony\Component\Serializer\Exception\CircularReferenceException
     */
    public function testUnableToNormalizeCircularReference()
    {
        $serializer = new Serializer(array($this->normalizer));
        $this->normalizer->setSerializer($serializer);
        $this->normalizer->setCircularReferenceLimit(2);

        $obj = new CircularReferenceDummy();

        $this->normalizer->normalize($obj);
    }

    public function testSiblingReference()
    {
        $serializer = new Serializer(array($this->normalizer));
        $this->normalizer->setSerializer($serializer);

        $siblingHolder = new SiblingHolder();

        $expected = array(
            'sibling0' => array('coopTilleuls' => 'Les-Tilleuls.coop'),
            'sibling1' => array('coopTilleuls' => 'Les-Tilleuls.coop'),
            'sibling2' => array('coopTilleuls' => 'Les-Tilleuls.coop'),
        );
        $this->assertEquals($expected, $this->normalizer->normalize($siblingHolder));
    }

    public function testCircularReferenceHandler()
    {
        $serializer = new Serializer(array($this->normalizer));
        $this->normalizer->setSerializer($serializer);
        $this->normalizer->setCircularReferenceHandler(function ($obj) {
            return get_class($obj);
        });

        $obj = new CircularReferenceDummy();

        $expected = array('me' => 'Symfony\Component\Serializer\Tests\Fixtures\CircularReferenceDummy');
        $this->assertEquals($expected, $this->normalizer->normalize($obj));
    }
}

class GetSetDummy
{
    protected $foo;
    private $bar;
    private $baz;
    protected $camelCase;
    protected $object;

    public function getFoo()
    {
        return $this->foo;
    }

    public function setFoo($foo)
    {
        $this->foo = $foo;
    }

    public function getBar()
    {
        return $this->bar;
    }

    public function setBar($bar)
    {
        $this->bar = $bar;
    }

    public function isBaz()
    {
        return $this->baz;
    }

    public function setBaz($baz)
    {
        $this->baz = $baz;
    }

    public function getFooBar()
    {
        return $this->foo.$this->bar;
    }

    public function getCamelCase()
    {
        return $this->camelCase;
    }

    public function setCamelCase($camelCase)
    {
        $this->camelCase = $camelCase;
    }

    public function otherMethod()
    {
        throw new \RuntimeException('Dummy::otherMethod() should not be called');
    }

    public function setObject($object)
    {
        $this->object = $object;
    }

    public function getObject()
    {
        return $this->object;
    }
}

class GetConstructorDummy
{
    protected $foo;
    private $bar;
    private $baz;

    public function __construct($foo, $bar, $baz)
    {
        $this->foo = $foo;
        $this->bar = $bar;
        $this->baz = $baz;
    }

    public function getFoo()
    {
        return $this->foo;
    }

    public function getBar()
    {
        return $this->bar;
    }

    public function isBaz()
    {
        return $this->baz;
    }

    public function otherMethod()
    {
        throw new \RuntimeException('Dummy::otherMethod() should not be called');
    }
}

abstract class SerializerNormalizer implements SerializerInterface, NormalizerInterface
{
}

class GetConstructorOptionalArgsDummy
{
    protected $foo;
    private $bar;
    private $baz;

    public function __construct($foo, $bar = array(), $baz = array())
    {
        $this->foo = $foo;
        $this->bar = $bar;
        $this->baz = $baz;
    }

    public function getFoo()
    {
        return $this->foo;
    }

    public function getBar()
    {
        return $this->bar;
    }

    public function getBaz()
    {
        return $this->baz;
    }

    public function otherMethod()
    {
        throw new \RuntimeException('Dummy::otherMethod() should not be called');
    }
}
