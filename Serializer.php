<?php
/**
 *
 * author: Wei Chang Yue
 * date: 2017/10/10 18:08
 * version: 1.0
 */


namespace sndwow\rest;

use yii\helpers\ArrayHelper;

class serializer extends \yii\rest\Serializer
{
    public $expandParam = '_expand';
    public $fieldsParam = '_fields';
    
    protected function getRequestedFields()
    {
        $expand = $this->formatExpand();
        $fields = $this->formatFields();
        
        return [$fields, $expand];
    }
    
    private function formatFields()
    {
        $param = $this->request->get($this->fieldsParam);
        $arr = is_string($param) ? preg_split('/\s*,\s*/', $param, -1, PREG_SPLIT_NO_EMPTY) : [];
        $fields = [];
        
        // 提取主表信息
        foreach ($arr as $item) {
            $pairs = explode('.', $item);
            if (count($pairs) === 1) {
                $fields['_fields'][] = $item;
            } else {
                $fields = ArrayHelper::merge($fields, $this->inlineFields($pairs));
            }
        }
        return $fields;
    }
    
    private function inlineFields($arr)
    {
        $data = [];
        $val = array_shift($arr);
        if (!is_null($val)) {
            if (count($arr) === 1) {
                $data[$val]['_fields'][] = $arr[0];
            } else {
                $data[$val] = $this->inlineFields($arr);
            }
        }
        return $data;
    }
    
    
    private function formatExpand()
    {
        $param = $this->request->get($this->expandParam);
        $arr = is_string($param) ? preg_split('/\s*,\s*/', $param, -1, PREG_SPLIT_NO_EMPTY) : [];
        $expand = [];
        foreach ($arr as $item) {
            $pairs = explode('.', $item);
            $expand = ArrayHelper::merge($expand, $this->inlineExpand($pairs));
        }
        return $expand;
    }
    
    private function inlineExpand($arr)
    {
        $data = [];
        $val = array_shift($arr);
        if (!is_null($val)) {
            $data[$val] = $this->inlineExpand($arr);
        }
        return $data;
    }
}