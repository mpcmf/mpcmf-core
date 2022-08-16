<?php

namespace mpcmf\system\storage;

class mysqlCursor implements mpcmfCursor 
{
    /**
     * @var \LessQL\Result
     */
    protected $mysqlResult;

    protected $requestParams = [
        'batchSize' => 10,
        'offset' => 0,
        'limit' => 100, 
    ];

    /**
     * @var \LessQL\Row[]
     */
    protected $session = [
        'requestOffset' => 0,
        'pos' => 0,
        'rows' => null,
        'needNextRequest' => true,
    ];

    public function __construct(\LessQL\Result $mysqlResult) 
    {
        $this->mysqlResult = $mysqlResult;
    }

    public function current()
    {
        return $this->session['rows'][$this->session['pos']]->getData();
    }

    public function next()
    {
        $this->session['pos']++;
        if($this->session['pos'] >= $this->requestParams['batchSize']) {
            $this->makeNextBatchRequest();
        }
    }

    public function key()
    {
        return $this->session['requestOffset'] + $this->session['pos'];
    }

    public function valid()
    {
        return isset($this->session['rows'][$this->session['pos']]);
    }

    public function rewind()
    {
        $this->makeNextBatchRequest();
    }

    public function skip($num)
    {
        $this->requestParams['offset'] = $num;
        $this->session['requestOffset'] = $this->requestParams['offset'];
        //@TODO: recalculate on skip change 
    }

    public function limit($num)
    {
        $this->requestParams['limit'] = $num;
        //@TODO: recalculate on limit change
    }

    public function count()
    {
        return $this->mysqlResult->count();
    }
    
    protected function makeNextBatchRequest() 
    {
        if(!$this->session['needNextRequest']) {
            $this->session['rows'] = null;
            
            return false;
        }
        
        $this->session['pos'] = 0;

        $this->mysqlResult->limit($this->requestParams['batchSize'], $this->session['requestOffset']);
        $this->session['rows'] = $this->mysqlResult->fetchAll();
        
        $this->session['requestOffset'] += $this->requestParams['batchSize'];
        if($this->session['requestOffset'] >= $this->requestParams['limit']) {
            $this->session['needNextRequest'] = false;
        }
        
        return true;
    }
}