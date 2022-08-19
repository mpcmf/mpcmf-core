<?php

namespace mpcmf\system\storage;

use LessQL\Result;
use LessQL\Row;
use mpcmf\system\storage\interfaces\mpcmfCursor;

class lessqlCursor implements mpcmfCursor 
{
    /**
     * @var Result
     */
    protected $lessqlResult;

    protected $requestParams = [
        'batchSize' => 10,
        'offset' => 0,
        'limit' => 100, 
    ];

    /**
     * @var array{'rows':Row[]}
     */
    protected $session = [
        'requestOffset' => 0,
        'pos' => 0,
        'rows' => null,
        'needNextRequest' => true,
    ];

    public function __construct(Result $lessqlResult) 
    {
        $this->lessqlResult = $lessqlResult;
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
        return $this->lessqlResult->count();
    }
    
    protected function makeNextBatchRequest() 
    {
        if(!$this->session['needNextRequest']) {
            $this->session['rows'] = null;
            
            return false;
        }
        
        $this->session['pos'] = 0;

        $this->lessqlResult->limit($this->requestParams['batchSize'], $this->session['requestOffset']);
        $this->session['rows'] = $this->lessqlResult->fetchAll();
        
        $this->session['requestOffset'] += $this->requestParams['batchSize'];
        if($this->session['requestOffset'] >= $this->requestParams['limit']) {
            $this->session['needNextRequest'] = false;
        }
        
        return true;
    }
}