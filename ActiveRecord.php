<?php
/**
 * 强化model关联
 * author: Wei Chang Yue
 * date: 2017/09/27 18:06
 * version: 1.0
 */

namespace sndwow\rest;

use yii\helpers\ArrayHelper;
use yii\web\Link;
use yii\web\Linkable;

class ActiveRecord extends \yii\db\ActiveRecord
{
    public function toArray(array $fields = [], array $expand = [], $recursive = true)
    {
        $data = [];
        $expandKeys = array_keys($expand);
        $_fields = [];
        foreach ($fields as $item) {
            if (is_string($item)) {
                $_fields[] = $item;
            }
        }
        
        foreach ($this->resolveFields($_fields, $expandKeys) as $field => $definition) {
            $data[$field] = is_string($definition) ? $this->$definition : call_user_func($definition, $this, $field);
        }
        if ($this instanceof Linkable) {
            $data['_links'] = Link::serialize($this->getLinks());
        }
        
        foreach ($expandKeys as $key) {
            if (isset($data[$key])) {
                $rel = $data[$key];
                $nextFields = isset($fields[$key]) ? $fields[$key] : [];
                if (is_array($rel)) {
                    foreach ($rel as $k => $v) {
                        $data[$key][$k] = $v->toArray($nextFields, $expand[$key]);
                    }
                } else if (is_object($rel)) {
                    $data[$key] = $rel->toArray($nextFields, $expand[$key]);
                } else {
                    $data[$key] = ArrayHelper::toArray($rel);
                }
            }
        }
        
        return $data;
    }
}