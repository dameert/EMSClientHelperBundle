<?php

namespace EMS\ClientHelperBundle\EMSBackendBridgeBundle\Elasticsearch;

use Elasticsearch\Client;
use EMS\ClientHelperBundle\EMSBackendBridgeBundle\Service\RequestService;

class ClientRequest
{
    /**
     * @var Client
     */
    private $client;
    
    /**
     * @var RequestService
     */
    private $requestService;
    
    /**
     * @var string
     */
    private $indexPrefix;
    
    /**
     * @param Client         $client
     * @param RequestService $requestService
     * @param string         $indexPrefix
     */
    public function __construct(
        Client $client, 
        RequestService $requestService,
        $indexPrefix
    ) {
        $this->client = $client;
        $this->requestService = $requestService;
        $this->indexPrefix = $indexPrefix;
    }
    
    /**
     * @param string $emsLink
     *
     * @return string|null
     */
    public static function getOuuid($emsLink)
    {
        if (!strpos($emsLink, ':')) {
            return $emsLink;
        }
        
        $split = preg_split('/:/', $emsLink);
        
        return array_pop($split);
    }
    
    /**
     * @param string $emsLink
     *
     * @return string|null
     */
    public static function getType($emsLink)
    {
        if (!strpos($emsLink, ':')) {
            return $emsLink;
        }
        
        $split = preg_split('/:/', $emsLink);
        
        return $split[0];
    }
    
    /**
     * @param string $emsLink
     *
     * @return string|null
     */
    public function searchBy($type, $parameters, $from = 0, $size = 10)
    {
        $body = [
            'query' => [
                'bool' => [
                    'must' => [],
                ],
            ],
        ];
        
        foreach ($parameters as $id => $value){
            $body['query']['bool']['must'][] = [
                'term' => [
                    $id => [
                        'value' => $value,
                    ]
                 ]
            ];
        }
        
        return $this->client->search([
            'index' => $this->getIndex(),
            'type' => $type,
            'body' => $body,
            'size' => $size,
            'from' => $from,
        ]);
    }
    
    /**
     * @param string $emsLink
     *
     * @return string|null
     */
    public function searchOneBy($type, $parameters)
    {
        $result = $this->searchBy($type, $parameters, 0, 1);
        if($result['hits']['total'] == 1) {
            return $result['hits']['hits'][0];
        }
        
        return false;
    }
    
    /**
     * @return string
     */
    public function getLocale()
    {
        return $this->requestService->getLocale();
    }
    
    /**
     * @param string $type
     * @param string $id
     *
     * @return array
     */
    public function get($type, $id)
    {
        return $this->client->get([
            'index' => $this->getIndex(),
            'type' => $type,
            'id' => $id,
        ]);
    }
    
    /**
     * @param string $type
     * @param string $id
     *
     * @return array
     */
    public function getByOuuids($type, $ouuids)
    {
        
        return $this->client->search([
            'index' => $this->getIndex(),
            'type' => $type,
            'body' => [
                'query' => [
                    'terms' => [
                        '_id' => $ouuids
                    ]
                ]
            ]
        ]);
    }
    
    /**
     * @param string $type
     * @param string $id
     *
     * @return array
     */
    public function getByOuuid($type, $ouuid)
    {
        $result = $this->client->search([
            'index' => $this->getIndex(),
            'type' => $type,
            'body' => [
                'query' => [
                    'term' => [
                        '_id' => $ouuid
                    ]
                ]
            ]
        ]);
        if(isset($result['hits']['hits'][0])) {
            return $result['hits']['hits'][0];
        }
        return false;;
    }
    
    public function analyze($text, $searchField) {
        $info = $this->client->indices()->getFieldMapping([
            'index' => $this->getFirstIndex(),
            'field' => $searchField,
        ]);
        
        $analyzer = 'standard';
        while(is_array($info = array_shift($info)) ){
            if(isset($info['analyzer'])) {
                $analyzer = $info['analyzer'];
            }
            else if(isset($info['mapping'])) {
                $info = $info['mapping'];
            }
        }
        
        $tokens = $this->client->indices()->analyze([
            'index' => $this->getFirstIndex(),
            'analyzer' => $analyzer,
            'text' => $text
            
        ]);
        
        $out = [];
        foreach ($tokens['tokens'] as $token){
            $out[] = $token['token'];
        }
        return $out;
    }
    
    /**
     * @param string $type
     * @param array  $body
     * 
     * @return array
     */
    public function search($type, array $body, $from = 0, $size = 10)
    {

        $params = [
            'index' => $this->getIndex(),
            'type' => $type,
            'body' => $body,
            'size' => $size,
            'from' => $from
        ];
        
        if ($from > 0) {
//             $params['preference'] = '_primary';
        }
        
        return $this->client->search($params);
    }
    
    /**
     * @param string $type
     * @param array  $body
     * 
     * @return array
     *
     * @throws \Exception
     */
    public function searchOne($type, array $body)
    {
        $search = $this->search($type, $body);
        
        $hits = $search['hits'];
        
        if (1 != $hits['total']) {
            throw new \Exception(sprintf('expected 1 result, got %d', $hits['total']));
        }
        
        return $hits['hits'][0];
    }
    
    /**
     * @param string|array $type
     * @param array  $body
     * @param int    $size
     * 
     * //http://stackoverflow.com/questions/10836142/elasticsearch-duplicate-results-with-paging
     */
    public function searchAll($type, array $body, $pageSize = 10)
    {
        $params = [
            'preference' => '_primary', //see function description
            //TODO: should be replace by an order by _ouid (in case of insert in the index the pagination will be inconsistent)
            'from' => 0,
            'size' => 0,
            'index' => $this->getIndex(),
            'type' => $type,
            'body' => $body,
        ];
        
        $totalSearch = $this->client->search($params);
        $total = $totalSearch["hits"]["total"];
        
        $results = [];
        $params['size'] = $pageSize;
        
        while($params['from'] < $total){
            $search = $this->client->search($params);
            
            foreach ($search["hits"]["hits"] as $document){
                $results[] = $document;
            }
            
            $params['from'] += $pageSize;
        }
        
        return $results;
    }
    
    /**
     * @return string
     */
    private function getIndex()
    {
        $prefixes = explode('|', $this->indexPrefix);
        $out = [];
        foreach ($prefixes as $prefix) {
            $out[] = $prefix . $this->requestService->getEnvironment();
        }
        if(!empty($out)){
            return $out;
        }
        return $this->indexPrefix . $this->requestService->getEnvironment();
    }
    
    /**
     * @return string
     */
    private function getFirstIndex()
    {
        $indexes = $this->getIndex();
        if(is_array($indexes) && count($indexes) > 0){
            return $indexes[0];
        }
        return $indexes;
    }
}
