<?php

namespace AlgoWeb\PODataLaravel\Serialisers;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use POData\Common\InvalidOperationException;
use POData\Common\Messages;
use POData\Common\ODataConstants;
use POData\Common\ODataException;
use POData\IService;
use POData\ObjectModel\IObjectSerialiser;
use POData\ObjectModel\ODataBagContent;
use POData\ObjectModel\ODataCategory;
use POData\ObjectModel\ODataEntry;
use POData\ObjectModel\ODataFeed;
use POData\ObjectModel\ODataLink;
use POData\ObjectModel\ODataMediaLink;
use POData\ObjectModel\ODataNavigationPropertyInfo;
use POData\ObjectModel\ODataProperty;
use POData\ObjectModel\ODataPropertyContent;
use POData\ObjectModel\ODataTitle;
use POData\ObjectModel\ODataURL;
use POData\ObjectModel\ODataURLCollection;
use POData\Providers\Metadata\IMetadataProvider;
use POData\Providers\Metadata\ResourceEntityType;
use POData\Providers\Metadata\ResourceProperty;
use POData\Providers\Metadata\ResourcePropertyKind;
use POData\Providers\Metadata\ResourceSet;
use POData\Providers\Metadata\ResourceSetWrapper;
use POData\Providers\Metadata\ResourceType;
use POData\Providers\Metadata\ResourceTypeKind;
use POData\Providers\Metadata\Type\Binary;
use POData\Providers\Metadata\Type\Boolean;
use POData\Providers\Metadata\Type\DateTime;
use POData\Providers\Metadata\Type\IType;
use POData\Providers\Metadata\Type\StringType;
use POData\Providers\Query\QueryResult;
use POData\Providers\Query\QueryType;
use POData\UriProcessor\QueryProcessor\ExpandProjectionParser\ExpandedProjectionNode;
use POData\UriProcessor\QueryProcessor\ExpandProjectionParser\ProjectionNode;
use POData\UriProcessor\QueryProcessor\ExpandProjectionParser\RootProjectionNode;
use POData\UriProcessor\QueryProcessor\OrderByParser\InternalOrderByInfo;
use POData\UriProcessor\RequestDescription;
use POData\UriProcessor\SegmentStack;

class IronicSerialiser implements IObjectSerialiser
{
    use SerialiseDepWrapperTrait;
    use SerialisePropertyCacheTrait;
    use SerialiseNavigationTrait;
    use SerialiseLowLevelWritersTrait;
    use SerialiseNextPageLinksTrait;

    /**
     * Update time to insert into ODataEntry/ODataFeed fields
     * @var Carbon
     */
    private $updated;

    /**
     * Has base URI already been written out during serialisation?
     * @var bool
     */
    private $isBaseWritten = false;

    /**
     * @param IService                $service Reference to the data service instance
     * @param RequestDescription|null $request Type instance describing the client submitted request
     * @throws \Exception
     */
    public function __construct(IService $service, RequestDescription $request = null)
    {
        $this->service = $service;
        $this->request = $request;
        $this->absoluteServiceUri = $service->getHost()->getAbsoluteServiceUri()->getUrlAsString();
        $this->absoluteServiceUriWithSlash = rtrim($this->absoluteServiceUri, '/') . '/';
        $this->stack = new SegmentStack($request);
        $this->complexTypeInstanceCollection = [];
        $this->modelSerialiser = new ModelSerialiser();
        $this->updated = Carbon::now();
    }

    /**
     * Write a top level entry resource.
     *
     * @param QueryResult $entryObject Reference to the entry object to be written
     *
     * @return ODataEntry|null
     * @throws InvalidOperationException
     * @throws \ReflectionException
     * @throws ODataException
     */
    public function writeTopLevelElement(QueryResult $entryObject)
    {
        if (!isset($entryObject->results)) {
            array_pop($this->lightStack);
            return null;
        }
        if (!$entryObject->results instanceof Model) {
            $res = $entryObject->results;
            $msg = is_array($res) ? 'Entry object must be single Model' : get_class($res);
            throw new InvalidOperationException($msg);
        }

        $this->loadStackIfEmpty();
        $baseURI = $this->isBaseWritten ? null : $this->absoluteServiceUriWithSlash;
        $this->isBaseWritten = true;

        $stackCount = count($this->lightStack);
        $topOfStack = $this->lightStack[$stackCount-1];
        $payloadClass = get_class($entryObject->results);
        /** @var ResourceEntityType $resourceType */
        $resourceType = $this->getService()->getProvidersWrapper()->resolveResourceType($topOfStack['type']);

        // need gubbinz to unpack an abstract resource type
        $resourceType = $this->getConcreteTypeFromAbstractType($resourceType, $payloadClass);

        // make sure we're barking up right tree
        if (!$resourceType instanceof ResourceEntityType) {
            throw new InvalidOperationException(get_class($resourceType));
        }

        /** @var Model $res */
        $res = $entryObject->results;
        $targClass = $resourceType->getInstanceType()->getName();
        if (!($res instanceof $targClass)) {
            $msg = 'Object being serialised not instance of expected class, '
                   . $targClass . ', is actually ' . $payloadClass;
            throw new InvalidOperationException($msg);
        }

        $this->checkRelationPropertiesCached($targClass, $resourceType);
        /** @var ResourceProperty[] $relProp */
        $relProp = $this->propertiesCache[$targClass]['rel'];
        /** @var ResourceProperty[] $nonRelProp */
        $nonRelProp = $this->propertiesCache[$targClass]['nonRel'];

        $resourceSet = $resourceType->getCustomState();
        if (!$resourceSet instanceof ResourceSet) {
            throw new InvalidOperationException('');
        }
        $title = $resourceType->getName();
        $type = $resourceType->getFullName();

        $relativeUri = $this->getEntryInstanceKey(
            $res,
            $resourceType,
            $resourceSet->getName()
        );
        $absoluteUri = rtrim($this->absoluteServiceUri, '/') . '/' . $relativeUri;

        /** var $mediaLink ODataMediaLink|null */
        $mediaLink = null;
        /** var $mediaLinks ODataMediaLink[] */
        $mediaLinks = [];
        $this->writeMediaData(
            $res,
            $type,
            $relativeUri,
            $resourceType,
            $mediaLink,
            $mediaLinks
        );

        $propertyContent = $this->writePrimitiveProperties($res, $nonRelProp);

        $links = $this->buildLinksFromRels($entryObject, $relProp, $relativeUri);

        $odata = new ODataEntry();
        $odata->resourceSetName = $resourceSet->getName();
        $odata->id = $absoluteUri;
        $odata->title = new ODataTitle($title);
        $odata->type = new ODataCategory($type);
        $odata->propertyContent = $propertyContent;
        $odata->isMediaLinkEntry = $resourceType->isMediaLinkEntry();
        $odata->editLink = new ODataLink();
        $odata->editLink->url = $relativeUri;
        $odata->editLink->name = 'edit';
        $odata->editLink->title = $title;
        $odata->mediaLink = $mediaLink;
        $odata->mediaLinks = $mediaLinks;
        $odata->links = $links;
        $odata->updated = $this->getUpdated()->format(DATE_ATOM);
        $odata->baseURI = $baseURI;

        $newCount = count($this->lightStack);
        if ($newCount != $stackCount) {
            $msg = 'Should have ' . $stackCount . ' elements in stack, have ' . $newCount . ' elements';
            throw new InvalidOperationException($msg);
        }
        $this->lightStack[$newCount-1]['count']--;
        if (0 == $this->lightStack[$newCount-1]['count']) {
            array_pop($this->lightStack);
        }
        return $odata;
    }

    /**
     * Write top level feed element.
     *
     * @param QueryResult &$entryObjects Array of entry resources to be written
     *
     * @return ODataFeed
     * @throws InvalidOperationException
     * @throws ODataException
     * @throws \ReflectionException
     */
    public function writeTopLevelElements(QueryResult &$entryObjects)
    {
        $res = $entryObjects->results;
        if (!(is_array($res) || $res instanceof Collection)) {
            throw new InvalidOperationException('!is_array($entryObjects->results)');
        }
        if (is_array($res) && 0 == count($res)) {
            $entryObjects->hasMore = false;
        }
        if ($res instanceof Collection && 0 == $res->count()) {
            $entryObjects->hasMore = false;
        }

        $this->loadStackIfEmpty();

        $title = $this->getRequest()->getContainerName();
        $relativeUri = $this->getRequest()->getIdentifier();
        $absoluteUri = $this->getRequest()->getRequestUrl()->getUrlAsString();

        $selfLink = new ODataLink();
        $selfLink->name = 'self';
        $selfLink->title = $relativeUri;
        $selfLink->url = $relativeUri;

        $odata = new ODataFeed();
        $odata->title = new ODataTitle($title);
        $odata->id = $absoluteUri;
        $odata->selfLink = $selfLink;
        $odata->updated = $this->getUpdated()->format(DATE_ATOM);
        $odata->baseURI = $this->isBaseWritten ? null : $this->absoluteServiceUriWithSlash;
        $this->isBaseWritten = true;

        if ($this->getRequest()->queryType == QueryType::ENTITIES_WITH_COUNT()) {
            $odata->rowCount = $this->getRequest()->getCountValue();
        }
        $this->buildEntriesFromElements($res, $odata);

        $resourceSet = $this->getRequest()->getTargetResourceSetWrapper()->getResourceSet();
        $requestTop = $this->getRequest()->getTopOptionCount();
        $pageSize = $this->getService()->getConfiguration()->getEntitySetPageSize($resourceSet);
        $requestTop = (null === $requestTop) ? $pageSize+1 : $requestTop;

        if (true === $entryObjects->hasMore && $requestTop > $pageSize) {
            $this->buildNextPageLink($entryObjects, $odata);
        }

        return $odata;
    }

    /**
     * Write top level url element.
     *
     * @param QueryResult $entryObject The entry resource whose url to be written
     *
     * @return ODataURL
     * @throws InvalidOperationException
     * @throws ODataException
     * @throws \ReflectionException
     */
    public function writeUrlElement(QueryResult $entryObject)
    {
        $url = new ODataURL();
        /** @var Model|null $res */
        $res = $entryObject->results;
        if (null !== $res) {
            $currentResourceType = $this->getCurrentResourceSetWrapper()->getResourceType();
            $relativeUri = $this->getEntryInstanceKey(
                $res,
                $currentResourceType,
                $this->getCurrentResourceSetWrapper()->getName()
            );

            $url->url = rtrim($this->absoluteServiceUri, '/') . '/' . $relativeUri;
        }

        return $url;
    }

    /**
     * Write top level url collection.
     *
     * @param QueryResult $entryObjects Array of entry resources whose url to be written
     *
     * @return ODataURLCollection
     * @throws InvalidOperationException
     * @throws ODataException
     * @throws \ReflectionException
     */
    public function writeUrlElements(QueryResult $entryObjects)
    {
        $urls = new ODataURLCollection();
        if (!empty($entryObjects->results)) {
            $i = 0;
            foreach ($entryObjects->results as $entryObject) {
                if (!$entryObject instanceof QueryResult) {
                    $query = new QueryResult();
                    $query->results = $entryObject;
                } else {
                    $query = $entryObject;
                }
                $urls->urls[$i] = $this->writeUrlElement($query);
                ++$i;
            }

            if ($i > 0 && true === $entryObjects->hasMore) {
                $this->buildNextPageLink($entryObjects, $urls);
            }
        }

        if ($this->getRequest()->queryType == QueryType::ENTITIES_WITH_COUNT()) {
            $urls->count = $this->getRequest()->getCountValue();
        }

        return $urls;
    }

    /**
     * Write top level complex resource.
     *
     * @param QueryResult  &$complexValue The complex object to be written
     * @param string       $propertyName  The name of the complex property
     * @param ResourceType &$resourceType Describes the type of complex object
     *
     * @return ODataPropertyContent
     * @throws InvalidOperationException
     * @throws \ReflectionException
     */
    public function writeTopLevelComplexObject(QueryResult &$complexValue, $propertyName, ResourceType &$resourceType)
    {
        $result = $complexValue->results;

        $propertyContent = new ODataPropertyContent();
        $odataProperty = new ODataProperty();
        $odataProperty->name = $propertyName;
        $odataProperty->typeName = $resourceType->getFullName();
        if (null != $result) {
            $internalContent = $this->writeComplexValue($resourceType, $result);
            $odataProperty->value = $internalContent;
        }

        $propertyContent->properties[$propertyName] = $odataProperty;

        return $propertyContent;
    }

    /**
     * Write top level bag resource.
     *
     * @param QueryResult  &$BagValue     The bag object to be
     *                                    written
     * @param string       $propertyName  The name of the
     *                                    bag property
     * @param ResourceType &$resourceType Describes the type of
     *                                    bag object
     *
     * @return ODataPropertyContent
     * @throws InvalidOperationException
     * @throws \ReflectionException
     */
    public function writeTopLevelBagObject(QueryResult &$BagValue, $propertyName, ResourceType &$resourceType)
    {
        $result = $BagValue->results;

        $propertyContent = new ODataPropertyContent();
        $odataProperty = new ODataProperty();
        $odataProperty->name = $propertyName;
        $odataProperty->typeName = 'Collection(' . $resourceType->getFullName() . ')';
        $odataProperty->value = $this->writeBagValue($resourceType, $result);

        $propertyContent->properties[$propertyName] = $odataProperty;
        return $propertyContent;
    }

    /**
     * Write top level primitive value.
     *
     * @param  QueryResult          &$primitiveValue   The primitive value to be
     *                                                 written
     * @param  ResourceProperty     &$resourceProperty Resource property describing the
     *                                                 primitive property to be written
     * @return ODataPropertyContent
     * @throws InvalidOperationException
     * @throws \ReflectionException
     */
    public function writeTopLevelPrimitive(QueryResult &$primitiveValue, ResourceProperty &$resourceProperty = null)
    {
        if (null === $resourceProperty) {
            throw new InvalidOperationException('Resource property must not be null');
        }
        $propertyContent = new ODataPropertyContent();

        $odataProperty = new ODataProperty();
        $odataProperty->name = $resourceProperty->getName();
        $iType = $resourceProperty->getInstanceType();
        if (!$iType instanceof IType) {
            throw new InvalidOperationException(get_class($iType));
        }
        $odataProperty->typeName = $iType->getFullTypeName();
        if (null == $primitiveValue->results) {
            $odataProperty->value = null;
        } else {
            $rType = $resourceProperty->getResourceType()->getInstanceType();
            if (!$rType instanceof IType) {
                throw new InvalidOperationException(get_class($rType));
            }
            $odataProperty->value = $this->primitiveToString($rType, $primitiveValue->results);
        }

        $propertyContent->properties[$odataProperty->name] = $odataProperty;

        return $propertyContent;
    }

    /**
     * Get update timestamp.
     *
     * @return Carbon
     */
    public function getUpdated()
    {
        return $this->updated;
    }

    /**
     * @param Model $entityInstance
     * @param ResourceType $resourceType
     * @param string $containerName
     * @return string
     * @throws InvalidOperationException
     * @throws ODataException
     * @throws \ReflectionException
     */
    protected function getEntryInstanceKey($entityInstance, ResourceType $resourceType, $containerName)
    {
        $typeName = $resourceType->getName();
        $keyProperties = $resourceType->getKeyProperties();
        if (0 == count($keyProperties)) {
            throw new InvalidOperationException('count($keyProperties) == 0');
        }
        $keyString = $containerName . '(';
        $comma = null;
        foreach ($keyProperties as $keyName => $resourceProperty) {
            $keyType = $resourceProperty->getInstanceType();
            if (!$keyType instanceof IType) {
                throw new InvalidOperationException('$keyType not instanceof IType');
            }
            $keyName = $resourceProperty->getName();
            $keyValue = $entityInstance->$keyName;
            if (!isset($keyValue)) {
                throw ODataException::createInternalServerError(
                    Messages::badQueryNullKeysAreNotSupported($typeName, $keyName)
                );
            }

            $keyValue = $keyType->convertToOData($keyValue);
            $keyString .= $comma . $keyName . '=' . $keyValue;
            $comma = ',';
        }

        $keyString .= ')';

        return $keyString;
    }

    /**
     * @param $entryObject
     * @param $type
     * @param $relativeUri
     * @param ResourceType $resourceType
     * @param ODataMediaLink|null $mediaLink
     * @param ODataMediaLink[] $mediaLinks
     * @return void
     * @throws InvalidOperationException
     */
    protected function writeMediaData(
        $entryObject,
        $type,
        $relativeUri,
        ResourceType $resourceType,
        ODataMediaLink &$mediaLink = null,
        array &$mediaLinks = []
    ) {
        $context = $this->getService()->getOperationContext();
        $streamProviderWrapper = $this->getService()->getStreamProviderWrapper();
        if (null == $streamProviderWrapper) {
            throw new InvalidOperationException('Retrieved stream provider must not be null');
        }

        /** @var ODataMediaLink|null $mediaLink */
        $mediaLink = null;
        if ($resourceType->isMediaLinkEntry()) {
            $eTag = $streamProviderWrapper->getStreamETag2($entryObject, null, $context);
            $mediaLink = new ODataMediaLink($type, '/$value', $relativeUri . '/$value', '*/*', $eTag, 'edit-media');
        }
        /** @var ODataMediaLink[] $mediaLinks */
        $mediaLinks = [];
        if ($resourceType->hasNamedStream()) {
            $namedStreams = $resourceType->getAllNamedStreams();
            foreach ($namedStreams as $streamTitle => $resourceStreamInfo) {
                $readUri = $streamProviderWrapper->getReadStreamUri2(
                    $entryObject,
                    $resourceStreamInfo,
                    $context,
                    $relativeUri
                );
                $mediaContentType = $streamProviderWrapper->getStreamContentType2(
                    $entryObject,
                    $resourceStreamInfo,
                    $context
                );
                $eTag = $streamProviderWrapper->getStreamETag2(
                    $entryObject,
                    $resourceStreamInfo,
                    $context
                );

                $nuLink = new ODataMediaLink($streamTitle, $readUri, $readUri, $mediaContentType, $eTag);
                $mediaLinks[] = $nuLink;
            }
        }
    }

    /**
     * @param QueryResult $entryObject
     * @param ResourceProperty $prop
     * @param $nuLink
     * @param $propKind
     * @param $propName
     * @throws InvalidOperationException
     * @throws ODataException
     * @throws \ReflectionException
     */
    private function expandNavigationProperty(
        QueryResult $entryObject,
        ResourceProperty $prop,
        $nuLink,
        $propKind,
        $propName
    ) {
        $nextName = $prop->getResourceType()->getName();
        $nuLink->isExpanded = true;
        $value = $entryObject->results->$propName;
        $isCollection = ResourcePropertyKind::RESOURCESET_REFERENCE == $propKind;
        $nuLink->isCollection = $isCollection;

        if (is_array($value)) {
            if (1 == count($value) && !$isCollection) {
                $value = $value[0];
            } else {
                $value = collect($value);
            }
        }

        $result = new QueryResult();
        $result->results = $value;
        $nullResult = null === $value;
        $isSingleton = $value instanceof Model;
        $resultCount = $nullResult ? 0 : ($isSingleton ? 1 : $value->count());

        if (0 < $resultCount) {
            $newStackLine = ['type' => $nextName, 'prop' => $propName, 'count' => $resultCount];
            array_push($this->lightStack, $newStackLine);
            if (!$isCollection) {
                $nuLink->type = 'application/atom+xml;type=entry';
                $expandedResult = $this->writeTopLevelElement($result);
            } else {
                $nuLink->type = 'application/atom+xml;type=feed';
                $expandedResult = $this->writeTopLevelElements($result);
            }
            $nuLink->expandedResult = $expandedResult;
        } else {
            $type = $this->getService()->getProvidersWrapper()->resolveResourceType($nextName);
            if (!$isCollection) {
                $result = new ODataEntry();
                $result->resourceSetName = $type->getName();
            } else {
                $result = new ODataFeed();
                $result->selfLink = new ODataLink();
                $result->selfLink->name = ODataConstants::ATOM_SELF_RELATION_ATTRIBUTE_VALUE;
            }
            $nuLink->expandedResult = $result;
        }
        if (isset($nuLink->expandedResult->selfLink)) {
            $nuLink->expandedResult->selfLink->title = $propName;
            $nuLink->expandedResult->selfLink->url = $nuLink->url;
            $nuLink->expandedResult->title = new ODataTitle($propName);
            $nuLink->expandedResult->id = rtrim($this->absoluteServiceUri, '/') . '/' . $nuLink->url;
        }
        if (!isset($nuLink->expandedResult)) {
            throw new InvalidOperationException('');
        }
    }

    public static function isMatchPrimitive($resourceKind)
    {
        if (16 > $resourceKind) {
            return false;
        }
        if (28 < $resourceKind) {
            return false;
        }
        return 0 == ($resourceKind % 4);
    }

    /**
     * @param ResourceEntityType $resourceType
     * @param $payloadClass
     * @return ResourceEntityType|ResourceType
     * @throws InvalidOperationException
     * @throws \ReflectionException
     */
    protected function getConcreteTypeFromAbstractType(ResourceEntityType $resourceType, $payloadClass)
    {
        if ($resourceType->isAbstract()) {
            $derived = $this->getMetadata()->getDerivedTypes($resourceType);
            if (0 == count($derived)) {
                throw new InvalidOperationException('Supplied abstract type must have at least one derived type');
            }
            $derived = array_filter(
                $derived,
                function (ResourceType $element) {
                    return !$element->isAbstract();
                }
            );
            foreach ($derived as $rawType) {
                $name = $rawType->getInstanceType()->getName();
                if ($payloadClass == $name) {
                    $resourceType = $rawType;
                    break;
                }
            }
        }
        // despite all set up, checking, etc, if we haven't picked a concrete resource type,
        // wheels have fallen off, so blow up
        if ($resourceType->isAbstract()) {
            throw new InvalidOperationException('Concrete resource type not selected for payload ' . $payloadClass);
        }
        return $resourceType;
    }

    /**
     * @param QueryResult $entryObject
     * @param array $relProp
     * @param $relativeUri
     * @return array
     * @throws InvalidOperationException
     * @throws ODataException
     * @throws \ReflectionException
     */
    protected function buildLinksFromRels(QueryResult $entryObject, array $relProp, $relativeUri)
    {
        $links = [];
        foreach ($relProp as $prop) {
            $nuLink = new ODataLink();
            $propKind = $prop->getKind();

            if (!(ResourcePropertyKind::RESOURCESET_REFERENCE == $propKind
                  || ResourcePropertyKind::RESOURCE_REFERENCE == $propKind)) {
                $msg = '$propKind != ResourcePropertyKind::RESOURCESET_REFERENCE &&'
                       . ' $propKind != ResourcePropertyKind::RESOURCE_REFERENCE';
                throw new InvalidOperationException($msg);
            }
            $propTail = ResourcePropertyKind::RESOURCE_REFERENCE == $propKind ? 'entry' : 'feed';
            $propType = 'application/atom+xml;type=' . $propTail;
            $propName = $prop->getName();
            $nuLink->title = $propName;
            $nuLink->name = ODataConstants::ODATA_RELATED_NAMESPACE . $propName;
            $nuLink->url = $relativeUri . '/' . $propName;
            $nuLink->type = $propType;
            $nuLink->isExpanded = false;
            $nuLink->isCollection = 'feed' === $propTail;

            $shouldExpand = $this->shouldExpandSegment($propName);

            $navProp = new ODataNavigationPropertyInfo($prop, $shouldExpand);
            if ($navProp->expanded) {
                $this->expandNavigationProperty($entryObject, $prop, $nuLink, $propKind, $propName);
            }
            $nuLink->isExpanded = isset($nuLink->expandedResult);
            if (null === $nuLink->isCollection) {
                throw new InvalidOperationException('');
            }

            $links[] = $nuLink;
        }
        return $links;
    }

    /**
     * @param $res
     * @param ODataFeed $odata
     * @throws InvalidOperationException
     * @throws ODataException
     * @throws \ReflectionException
     */
    protected function buildEntriesFromElements($res, ODataFeed $odata)
    {
        foreach ($res as $entry) {
            if (!$entry instanceof QueryResult) {
                $query = new QueryResult();
                $query->results = $entry;
            } else {
                $query = $entry;
            }
            $odata->entries[] = $this->writeTopLevelElement($query);
        }
    }
}
