<?php

namespace mpcmf\system\storage;


use mpcmf\system\pattern\singleton;

class mongo2sql
{
    use singleton;
    
    public function toSql($data): string
    {
        $sql = [];
        switch ($data[1]) {
            case 'select':
                $sql[] = 'SELECT';
                if (count($data[4]) > 0) {
                    $sql[] = implode(', ', $data[4]);
                } else {
                    $sql[] = '*';
                }
                $sql[] = 'FROM';
                $sql[] = $data[0];
                $sql[] = 'WHERE';
                $sql[] = $this->translateCriteria($data[2]);
                break;
            case 'selectOne':
                $sql[] = 'SELECT';
                if (count($data[4]) > 0) {
                    $sql[] = implode(', ', $data[4]);
                } else {
                    $sql[] = '*';
                }
                $sql[] = 'FROM';
                $sql[] = $data[0];
                $sql[] = 'WHERE';
                $sql[] = $this->translateCriteria($data[2]);
                $sql[] = 'LIMIT 1';
                break;
            case 'update':
            case 'updateFields':
                $sql[] = 'UPDATE';
                $sql[] = $data[0];
                $sql[] = 'SET';
                $sql[] = $this->translateCriteria($data[3]);
                $sql[] = 'WHERE';
                $sql[] = $this->translateCriteria($data[2]);
                break;
            case 'removeOne':
                $sql[] = 'DELETE FROM';
                $sql[] = $data[0];
                $sql[] = 'WHERE';
                $sql[] = $this->translateCriteria($data[2]);
                $sql[] = 'LIMIT 1';
                break;
            case 'remove':
                $sql[] = 'DELETE FROM';
                $sql[] = $data[0];
                $sql[] = 'WHERE';
                $sql[] = $this->translateCriteria($data[2]);
                break;
            case 'insert':
                $sql[] = 'INSERT INTO';
                $sql[] = $data[0];

                $sql[] = '(';
                $sql[] = implode(', ', $this->translateValueToScalarArray(array_keys($data[3])));
                $sql[] = ')';
                $sql[] = 'VALUES';
                $sql[] = '(';
                $sql[] = implode(', ', $this->translateValueToScalarArray(array_values($data[3])));
                $sql[] = ')';
                break;
            case 'insertBatch':
                $sql[] = 'INSERT INTO';
                $sql[] = $data[0];

                $first = reset($data[3]);

                $sql[] = '(';
                $sql[] = implode(', ', $this->translateValueToScalarArray(array_keys($first)));
                $sql[] = ')';
                foreach ($data[3] as $item) {
                    $sql[] = 'VALUES';
                    $sql[] = '(';
                    $sql[] = implode(', ', $this->translateValueToScalarArray(array_values($item)));
                    $sql[] = ')';
                    $sql[] = ',';
                }
                array_pop($sql);
                break;
            case 'save':
                $sql[] = 'REPLACE INTO';
                $sql[] = $data[0];

                $sql[] = '(';
                $sql[] = implode(', ', $this->translateValueToScalarArray(array_keys($data[3])));
                $sql[] = ')';
                $sql[] = 'VALUES';
                $sql[] = '(';
                $sql[] = implode(', ', $this->translateValueToScalarArray(array_values($data[3])));
                $sql[] = ')';
                break;
            default:
                break;
        }

        return implode(' ', $sql);
    }

    public function translateCriteria($criteria, $glue = 'AND'): string
    {
        if ($criteria == null) {
            return '1=1';
        }

        $sql = [];
        $sql[] = '(';

        foreach ($criteria as $key => $value) {
            if ($key === '$or') {
                $sql[] = $this->translateSubquery($value, 'OR');
            } elseif ($key === '$and') {
                $sql[] = $this->translateSubquery($value, 'AND');
            } elseif ($key === '$set') {
                return $this->translateCriteria($value, ', ');
            } else {
                if (!is_a($value, \MongoId::class, true) && !isset($value['$id']) && (is_array($value) || is_object($value))) {
                    $sql[] = $this->translateSubValue($key, $value);
                } else {
                    $sql[] = '(';
                    $sql[] = $key;
                    $sql[] = '=';
                    $sql[] = $this->translateValueToScalar($value);
                    $sql[] = ')';
                }
            }
            $sql[] = $glue;
        }
        array_pop($sql);
        $sql[] = ')';
        if(!isset($sql[2])) {
            return '1=1';
        }

        return implode(' ', $sql);
    }
    
    public function translateUpdatePayload($payload) 
    {
        //@TODO: add $inc, $push, etc
        return $payload['$set'] ?? $payload;
    }

    protected function translateValueToScalar($value): string
    {
        $result = null;
        switch (gettype($value)) {
            case "boolean":
                $result = $value ? 'true' : 'false';
                break;
            case "integer":
            case "double":
                $result = $value;
                break;
            case "string":
                $result = '"' . addslashes($value) . '"';
                break;
            case "array":
                if (isset($value['$id'])) {
                    $result = '"' . $value['$id'] . '"';
                } else {
                    $result = 'VALUE_TYPE_IS_NOT_IMPLEMENTED_ARRAY(' . json_encode($value) . ')';
                }
                break;
            case "object":
                if (is_a($value, \MongoId::class, true)) {
                    $result = '"' . ((string)$value) . '"';
                } else {
                    $result = 'VALUE_TYPE_IS_NOT_IMPLEMENTED_OBJECT(' . json_encode($value) . ')';
                }
                break;
            case "NULL":
                $result = 'null';
                break;
            default:
                break;
        }

        return $result;
    }

    protected function translateValueToScalarArray($value): array
    {
        foreach ($value as $key => $item) {
            $value[$key] = $this->translateValueToScalar($item);
        }

        return $value;
    }

    protected function translateSubValue($key, $value): string
    {
        $sql = [];
        $sql[] = '(';

        $type = array_keys($value)[0];
        if ($type === 0) {
            return $this->translateSubquery([$key => $value]);
        }

        switch ($type) {
            case '$gt':
                $sql[] = $key;
                $sql[] = '>';
                $sql[] = $this->translateValueToScalar($value[$type]);
                break;
            case '$gte':
                $sql[] = $key;
                $sql[] = '>=';
                $sql[] = $this->translateValueToScalar($value[$type]);;
                break;
            case '$lt':
                $sql[] = $key;
                $sql[] = '<';
                $sql[] = $this->translateValueToScalar($value[$type]);;
                break;
            case '$lte':
                $sql[] = $key;
                $sql[] = '<=';
                $sql[] = $this->translateValueToScalar($value[$type]);;
                break;
            case '$in':
                $cnt = count($value[$type]);
                if ($cnt > 0) {
                    if ($cnt == 1) {
                        $sql[] = $key;
                        $sql[] = '=';
                        $sql[] = $this->translateValueToScalar(reset($value[$type]));
                    } else {
                        $sql[] = $key;
                        $sql[] = 'IN';
                        $sql[] = '(';
                        $sql[] = implode(', ', $this->translateValueToScalarArray($value[$type]));
                        $sql[] = ')';
                    }
                } else {
                    $sql[] = $key;
                    $sql[] = 'IN';
                    $sql[] = '(';
                    $sql[] = ')';
                }
                break;
            case '$nin':
                $cnt = count($value[$type]);
                if ($cnt > 0) {
                    if ($cnt == 1) {
                        $sql[] = $key;
                        $sql[] = '!=';
                        $sql[] = $this->translateValueToScalar(reset($value[$type]));
                    } else {
                        $sql[] = $key;
                        $sql[] = 'NOT IN';
                        $sql[] = '(';
                        $sql[] = implode(', ', $this->translateValueToScalarArray($value[$type]));
                        $sql[] = ')';
                    }
                } else {
                    $sql[] = $key;
                    $sql[] = 'NOT IN';
                    $sql[] = '(';
                    $sql[] = ')';
                }
                break;
            case '$eq':
                $valTypeValue = $this->translateValueToScalar($value[$type]);
                $sql[] = $key;
                if ($valTypeValue == 'NULL') {
                    $sql[] = 'IS';
                } else {
                    $sql[] = '=';
                }
                $sql[] = $valTypeValue;
                break;
            case '$ne':
                $valTypeValue = $this->translateValueToScalar($value[$type]);
                $sql[] = $key;
                if ($valTypeValue == 'NULL') {
                    $sql[] = 'IS NOT';
                } else {
                    $sql[] = '!=';
                }
                $sql[] = $this->translateValueToScalar($value[$type]);;
                break;
            default:
                break;
        }

        $sql[] = ')';

        return implode(' ', $sql);
    }
    
    protected function translateSubquery($subquery, $glue = 'AND'): string
    {
        $sql = [];
        $sql[] = '(';
        foreach ($subquery as $query) {
            $sql[] = '(';
            $sql[] = $this->translateCriteria($query);
            $sql[] = ')';
            $sql[] = $glue;
        }
        array_pop($sql);
        $sql[] = ')';

        return implode(' ', $sql);
    }
}