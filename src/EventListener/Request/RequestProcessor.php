<?php declare(strict_types = 1);
/*
 * This file is part of the KleijnWeb\SwaggerBundle package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KleijnWeb\SwaggerBundle\EventListener\Request;

use KleijnWeb\PhpApi\Descriptions\Description\Repository;
use KleijnWeb\PhpApi\Descriptions\Description\Schema\ScalarSchema;
use KleijnWeb\PhpApi\Descriptions\Description\Schema\Validator\SchemaValidator;
use KleijnWeb\PhpApi\Descriptions\Request\RequestParameterAssembler;
use KleijnWeb\PhpApi\Hydrator\DateTimeSerializer;
use KleijnWeb\PhpApi\Hydrator\ObjectHydrator;
use KleijnWeb\SwaggerBundle\Exception\ValidationException;
use KleijnWeb\SwaggerBundle\Exception\MalformedContentException;
use Symfony\Component\HttpFoundation\Request;

/**
 * @author John Kleijn <john@kleijnweb.nl>
 */
class RequestProcessor
{
    /**
     * @var Repository
     */
    private $repository;

    /**
     * @var SchemaValidator
     */
    private $validator;

    /**
     * @var ObjectHydrator
     */
    private $hydrator;

    /**
     * @var RequestParameterAssembler
     */
    private $parametersAssembler;

    /**
     * @var DateTimeSerializer
     */
    private $dateTimeSerializer;

    /**
     * RequestProcessor constructor.
     *
     * @param Repository                $repository
     * @param SchemaValidator           $validator
     * @param RequestParameterAssembler $parametersAssembler
     * @param ObjectHydrator            $hydrator
     * @param DateTimeSerializer        $dateTimeSerializer
     */
    public function __construct(
        Repository $repository,
        SchemaValidator $validator,
        RequestParameterAssembler $parametersAssembler,
        ObjectHydrator $hydrator = null,
        DateTimeSerializer $dateTimeSerializer = null
    ) {
        $this->repository          = $repository;
        $this->validator           = $validator;
        $this->hydrator            = $hydrator;
        $this->parametersAssembler = $parametersAssembler;
        $this->dateTimeSerializer  = $dateTimeSerializer ?: new DateTimeSerializer();
    }


    /**
     * @param Request $request
     *
     * @throws ValidationException
     * @throws MalformedContentException
     */
    public function process(Request $request)
    {
        if (!$request->attributes->has(RequestMeta::ATTRIBUTE_URI)) {
            throw  new \UnexpectedValueException("Missing document URI");
        }
        $description = $this->repository->get($request->attributes->get(RequestMeta::ATTRIBUTE_URI));
        $operation   = $description
            ->getPath($request->attributes->get(RequestMeta::ATTRIBUTE_PATH))
            ->getOperation(
                $request->getMethod()
            );

        $body = null;
        if ($request->getContent()) {
            $body = json_decode($request->getContent());
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new MalformedContentException(json_last_error_msg());
            }
        }

        $result = $this->validator->validate(
            $operation->getRequestSchema(),
            $coercedParams = $this->parametersAssembler->assemble(
                $operation,
                $request->query->all(),
                $request->attributes->all(),
                $request->headers->all(),
                $body
            )
        );

        foreach ($coercedParams as $attribute => $value) {
            /** @var ScalarSchema  $schema*/
            if (($schema = $operation->getParameter($attribute)->getSchema()) instanceof ScalarSchema) {
                if ($schema->isDateTime()) {
                    $value = $this->dateTimeSerializer->deserialize($value, $schema);
                }
            }

            $request->attributes->set($attribute, $value);
        }
        if ($this->hydrator
            && $bodyParam = $description->getRequestBodyParameter($operation->getPath(), $operation->getMethod())
        ) {
            $body = $this->hydrator->hydrate($body, $bodyParam->getSchema());
            $request->attributes->set($bodyParam->getName(), $body);
        }

        $request->attributes->set(
            RequestMeta::ATTRIBUTE,
            new RequestMeta($description, $operation)
        );

        if (!$result->isValid()) {
            throw new ValidationException($result->getErrorMessages());
        }
    }
}
