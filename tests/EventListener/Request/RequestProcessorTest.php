<?php declare(strict_types = 1);
/*
 * This file is part of the KleijnWeb\SwaggerBundle package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KleijnWeb\SwaggerBundle\Tests\EventListener\Request;

use KleijnWeb\PhpApi\Descriptions\Description\Description;
use KleijnWeb\PhpApi\Descriptions\Description\Operation;
use KleijnWeb\PhpApi\Descriptions\Description\Parameter;
use KleijnWeb\PhpApi\Descriptions\Description\Repository;
use KleijnWeb\PhpApi\Descriptions\Description\Schema\ObjectSchema;
use KleijnWeb\PhpApi\Descriptions\Description\Schema\Schema;
use KleijnWeb\PhpApi\Descriptions\Description\Schema\Validator\SchemaValidator;
use KleijnWeb\PhpApi\Descriptions\Description\Schema\Validator\ValidationResult;
use KleijnWeb\PhpApi\Descriptions\Request\RequestParameterAssembler;
use KleijnWeb\PhpApi\Hydrator\ObjectHydrator;
use KleijnWeb\SwaggerBundle\EventListener\Request\RequestMeta;
use KleijnWeb\SwaggerBundle\EventListener\Request\RequestProcessor;
use KleijnWeb\SwaggerBundle\Exception\ValidationException;
use KleijnWeb\SwaggerBundle\Exception\MalformedContentException;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;

/**
 * @author John Kleijn <john@kleijnweb.nl>
 */
class RequestProcessorTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var  \PHPUnit_Framework_MockObject_MockObject
     */
    private $repositoryMock;

    /**
     * @var  \PHPUnit_Framework_MockObject_MockObject
     */
    private $validatorMock;

    /**
     * @var  \PHPUnit_Framework_MockObject_MockObject
     */
    private $hydratorMock;

    /**
     * @var  \PHPUnit_Framework_MockObject_MockObject
     */
    private $parametersAssemblerMock;

    /**
     * Create mocks
     */
    protected function setUp()
    {
        /** @var Repository $repository */
        $this->repositoryMock = $repository = $this
            ->getMockBuilder(Repository::class)
            ->disableOriginalConstructor()
            ->getMock();

        /** @var Repository $repository */
        $this->validatorMock = $validator = $this
            ->getMockBuilder(SchemaValidator::class)
            ->disableOriginalConstructor()
            ->getMock();

        /** @var RequestParameterAssembler $hydrator */
        $this->parametersAssemblerMock = $parametersAssembler = $this
            ->getMockBuilder(RequestParameterAssembler::class)
            ->disableOriginalConstructor()
            ->getMock();

        /** @var ObjectHydrator $hydrator */
        $this->hydratorMock = $hydrator = $this
            ->getMockBuilder(ObjectHydrator::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @test
     */
    public function willThrowExceptionIfRequestDoesNotHaveDocumentUri()
    {
        $processor = $this->createProcessor();

        $this->setExpectedException(\UnexpectedValueException::class);
        $processor->process(new Request());
    }

    /**
     * @test
     */
    public function willThrowExceptionWhenContentIsNotJson()
    {
        $processor = $this->createProcessor();

        $this->setExpectedException(MalformedContentException::class);

        $processor->process($this->createRequest([
            RequestMeta::ATTRIBUTE_URI  => '/uri',
            RequestMeta::ATTRIBUTE_PATH => '/path'
        ], 'not json'));
    }

    /**
     * @test
     */
    public function willAssembleParameters()
    {
        $processor = $this->createProcessor(true);
        $this->parametersAssemblerMock->expects($this->once())->method('assemble');

        $processor->process($this->createRequest([
            RequestMeta::ATTRIBUTE_URI  => '/uri',
            RequestMeta::ATTRIBUTE_PATH => '/path'
        ]));
    }

    /**
     * @test
     */
    public function willUpdateAttributes()
    {
        $processor         = $this->createProcessor();
        $coercedAttributes = (object)[
            'foo' => 'bar'
        ];

        $this->parametersAssemblerMock->expects($this->once())->method('assemble')->willReturn($coercedAttributes);

        $request = $this->createRequest([
            RequestMeta::ATTRIBUTE_URI  => '/uri',
            RequestMeta::ATTRIBUTE_PATH => '/path'
        ]);

        $processor->process($request);

        $this->assertTrue($request->attributes->has(RequestMeta::ATTRIBUTE_URI));
        $this->assertTrue($request->attributes->has(RequestMeta::ATTRIBUTE_PATH));
        $this->assertTrue($request->attributes->has('foo'));
        $this->assertSame('bar', $request->attributes->get('foo'));
    }

    /**
     * @test
     */
    public function canDecodeJsonBody()
    {
        $body = (object)['foo' => 'bar'];

        $processor = $this->createProcessor();

        $this->parametersAssemblerMock
            ->expects($this->once())
            ->method('assemble')
            ->willReturnCallback(function (
                Operation $operation,
                array $query,
                array $attributes,
                array $headers,
                \stdClass $body
            ) {
                return (object)['theBody' => $body];
            });

        $request = $this->createRequest([
            RequestMeta::ATTRIBUTE_URI  => '/uri',
            RequestMeta::ATTRIBUTE_PATH => '/path'
        ], json_encode($body));

        $processor->process($request);

        $this->assertEquals($body, $request->attributes->get('theBody'));
    }

    /**
     * @test
     */
    public function canHydrateJsonBody()
    {
        $body = (object)['theBody' => 'bar'];

        $processor = $this->createProcessor(true, true);

        $parameter = new Parameter('theBody', true, new ObjectSchema((object)[]), Parameter::IN_BODY);
        $descriptionMock = $this->getMockBuilder(Description::class)->disableOriginalConstructor()->getMock();
        $descriptionMock->expects($this->once())->method('getRequestBodyParameter')->willReturn($parameter);

        $this->repositoryMock->expects($this->once())->method('get')->willReturn($descriptionMock);

        $this->parametersAssemblerMock
            ->expects($this->once())
            ->method('assemble')
            ->willReturnCallback(function (
                Operation $operation,
                array $query,
                array $attributes,
                array $headers,
                \stdClass $body
            ) {
                return (object)['theBody' => $body];
            });

        $dto = new \ArrayObject;

        $this->hydratorMock
            ->expects($this->once())
            ->method('hydrate')
            ->with($body, $this->isInstanceOf(Schema::class))
            ->willReturnCallback(function () use ($dto) {
                return $dto;
            });

        $request = $this->createRequest([
            RequestMeta::ATTRIBUTE_URI  => '/uri',
            RequestMeta::ATTRIBUTE_PATH => '/path'
        ], json_encode($body));

        $processor->process($request);

        $this->assertSame($dto, $request->attributes->get('theBody'));
    }

    /**
     * @test
     */
    public function willSetRequestMetaAttribute()
    {
        $processor         = $this->createProcessor();
        $coercedAttributes = (object)[
            'foo' => 'bar'
        ];

        $this->parametersAssemblerMock->expects($this->once())->method('assemble')->willReturn($coercedAttributes);

        $request = $this->createRequest([
            RequestMeta::ATTRIBUTE_URI  => '/uri',
            RequestMeta::ATTRIBUTE_PATH => '/path'
        ]);

        $processor->process($request);

        $this->assertTrue($request->attributes->has(RequestMeta::ATTRIBUTE));
    }

    /**
     * @test
     */
    public function willThrowExceptionIfRequestIsNotValid()
    {
        $processor = $this->createProcessor(false);

        $this->setExpectedException(ValidationException::class);

        $processor->process($this->createRequest([
            RequestMeta::ATTRIBUTE_URI  => '/uri',
            RequestMeta::ATTRIBUTE_PATH => '/path'
        ]));
    }

    /**
     * @param bool $stubValidity
     * @param bool $useHydrator
     *
     * @return RequestProcessor
     */
    private function createProcessor(bool $stubValidity = true, bool $useHydrator = false): RequestProcessor
    {
        if ($stubValidity) {
            $this->validatorMock->expects($this->any())->method('validate')->willReturn(new ValidationResult(true));
        }
        /** @var Repository $repository */
        $repository = $this->repositoryMock;
        /** @var SchemaValidator $validator */
        $validator = $this->validatorMock;
        /** @var RequestParameterAssembler $parametersAssembler */
        $parametersAssembler = $this->parametersAssemblerMock;
        /** @var ObjectHydrator $hydrator */
        $hydrator = $this->hydratorMock;

        return new RequestProcessor(
            $repository,
            $validator,
            $parametersAssembler,
            ($useHydrator ? $hydrator : null)
        );
    }

    /**
     * @param array  $attributes
     *
     * @param string $content
     *
     * @return Request
     */
    private function createRequest(array $attributes, string $content = ''): Request
    {
        return new class($attributes, $content) extends Request
        {
            /**
             * @param array $attributes
             * @param array $content
             */
            public function __construct(array $attributes, $content)
            {
                parent::__construct();
                $this->attributes = new ParameterBag($attributes);
                $this->content    = $content;
            }
        };
    }
}
