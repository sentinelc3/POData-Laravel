<?php

declare(strict_types=1);

namespace AlgoWeb\PODataLaravel\Controllers;

use AlgoWeb\PODataLaravel\Controllers\Controller as BaseController;
use AlgoWeb\PODataLaravel\Serialisers\IronicSerialiser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use POData\Configuration\EntitySetRights;
use POData\Configuration\ServiceConfiguration;
use POData\OperationContext\ServiceHost as ServiceHost;
use AlgoWeb\PODataLaravel\OperationContext\Web\Illuminate\IlluminateOperationContext as OperationContextAdapter;
use POData\Providers\Metadata\IMetadataProvider;
use POData\SimpleDataService as DataService;

/**
 * Class ODataController
 * @package AlgoWeb\PODataLaravel\Controllers
 */
class ODataController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @param  Request                   $request
     * @throws \Exception
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $dryRun     = $this->isDryRun();
        $commitCall = $dryRun ? 'rollBack' : 'commit';

        try {
            DB::beginTransaction();
            $context = new OperationContextAdapter($request);
            $host    = new ServiceHost($context);
            $host->setServiceUri('/odata.svc/');

            $query = App::make('odataquery');
            $meta  = App::make('metadata');

            $config = $this->makeConfig($meta);
            $pageSize = $this->getAppPageSize();
            if (null !== $pageSize) {
                $config->setEntitySetPageSize('*', $pageSize);
            } else {
                $config->setEntitySetPageSize('*', 400);
            }
            $config->setEntitySetAccessRule('*', EntitySetRights::ALL());
            $config->setAcceptCountRequests(true);
            $config->setAcceptProjectionRequests(true);

            $service  = new DataService($query, $meta, $host, null, null, $config);
            $cereal   = new IronicSerialiser($service, null);
            $service  = new DataService($query, $meta, $host, $cereal, null, $config);

            $service->handleRequest();

            $odataResponse = $context->outgoingResponse();

            $content = $odataResponse->getStream();

            $headers      = $odataResponse->getHeaders();
            $responseCode = $headers[\POData\Common\ODataConstants::HTTPRESPONSE_HEADER_STATUS_CODE];
            $response     = new Response($content, intval($responseCode));

            foreach ($headers as $headerName => $headerValue) {
                if (null !== $headerValue) {
                    $response->headers->set($headerName, $headerValue);
                }
            }
            DB::$commitCall();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
        return $response;
    }

    /**
     * Is application dry-running (ie, not committing) non-READ requests?
     *
     * @return bool
     */
    protected function isDryRun()
    {
        $configDump = env('APP_DRY_RUN', false);
        return true === $configDump;
    }

    /**
     * @return int|null
     */
    protected function getAppPageSize()
    {
        /** @var mixed|null $size */
        $size = env('APP_PAGE_SIZE', null);
        return null !== $size ? intval($size) : null;
    }

    /**
     * @param IMetadataProvider|null $meta
     * @return ServiceConfiguration
     */
    protected function makeConfig(?IMetadataProvider $meta): ServiceConfiguration
    {
        $config = new ServiceConfiguration($meta);
        return $config;
    }
}
