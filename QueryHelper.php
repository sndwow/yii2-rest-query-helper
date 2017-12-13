<?php
/**
 *
 * author: Wei Chang Yue
 * date: 2017/12/08 14:07
 * version: 1.0
 */

namespace sndwow\rest;

use yii\db\ActiveQuery;
use Yii;
use yii\helpers\ArrayHelper;

class QueryHelper
{
    /*
     * 允许的查询规则，例如
     * [
     *  'id'=>['eq','!eq'], // 仅支持等于和不等于查询
     *  'name'=>'*', //支持所有查询类型
     *  'store.id'=>['eq'] // 关联表并查询id
     * ]
     */
    public $ruleWhere = [];
    //  支持的排序的字段，支持关联表，例如['id','create_time','store.id']
    public $ruleSort = [];
    
    // 分页下每页支持的最大数据条目数量，支持关联表。第一个元素是主表的值 例如：[20,'store'=>10,'store.storePost'=>10]
    public $rulePerpage = [];
    // 预处理查询数据集合
    public $prepareData = [];
    
    /* 支持的查询条件 */
    private $conditions = [
        'eq', // name = 'jack'
        '!eq', // name != 'jack'
        'like', // name LIKE '%jack%'
        'llike', // name LIKE '%jack'
        'rlike', // name LIKE 'jack%'
        'null', // name IS NULL
        '!null', // name IS NOT NULL
        'less_than', // money < 10
        'more_than', // money > 10
        'less_than_eq', // money <= 10
        'more_than_eq', // money >= 10
        'in', // id IN ('1','2')
        '!in', // id NOT IN ('1','2')
    ];
    
    // 查询参数（不包含扩展参数）
    private $_where = [];
    // 扩展查询
    private $_extend = [
        '_sort' => null,  // 排序，支持多表：_sort=id,subtable.id.desc
        '_fields' => null, // 结果集仅包含哪些字段，支持多表：_fields=id,name,subtable.id
        '_expand' => null, // 欲关联查询：_expand=subtable,subtable.other
    ];
    
    
    /* @var ActiveQuery $query */
    private $_mainQuery = null;
    /* @var ActiveQuery $query */
    private $_query = null;
    
    
    function __construct($modelClass)
    {
        /* @var $modelClass \yii\db\BaseActiveRecord */
        $this->_mainQuery = $modelClass::find();
        $this->_query = clone $this->_mainQuery;
    }
    
    /**
     * 批量设置接口开放的规则
     * @param array $rule
     */
    private function setRules(array $rule)
    {
        if (isset($rule['sort'])) {
            $this->ruleSort = $rule['sort'];
        }
        if (isset($rule['where'])) {
            $this->ruleWhere = $rule['where'];
        }
    }
    
    
    /**
     * 初始化url查询参数
     */
    private function parseParams()
    {
        $paramString = Yii::$app->request->queryString;
        $params = [];
        foreach (explode('&', $paramString) as $pair) {
            $tmp = explode('=', $pair, 2);
            if (count($tmp) === 2) {
                $params[$tmp[0]] = urldecode($tmp[1]);
            }
        }
        // 提取扩展查询参数
        foreach ($this->_extend as $k => $v) {
            if (isset($params[$k])) {
                $this->_extend[$k] = $params[$k];
                unset($params[$k]);
            }
        }
        $this->_where = $params;
    }
    
    public function build(array $rules)
    {
        // 设置可用的查询规则
        $this->setRules($rules);
        // 解析请求参数
        $this->parseParams();
        // 预处理关联查询
        $this->prepareRelated();
        // 预处理搜索条件
        $this->prepareWhere();
        // 预处理排序条件
        $this->prepareOrderBy();
        // 应用查询参数
        $this->applyData();
        return $this->_query;
    }
    
    /**
     * 获取请求参数，并匹配所设置的查询规则，返回格式化后的查询数据
     * @return array
     */
    private function formatWhereParams()
    {
        $format = [];
        foreach ($this->ruleWhere as $rKey => $rVal) {
            foreach ($this->_where as $pKey => $pVal) {
                // 匹配允许查询的字段
                if ($pKey != $rKey)
                    continue;
                
                // 拆解参数值
                $value = explode(':', $pVal, 2);
                if (count($value) === 2) {
                    // 格式为：id=like:10
                    if (in_array($value[0], $this->conditions)) {
                        $_rule = $value[0];
                        $_val = $value[1];
                    } else {
                        // 若查询条件不存在，默认为eq查询
                        $_rule = 'eq';
                        $_val = $pVal;
                    }
                } else {
                    // 格式为：id=100
                    if ($pVal == 'null' || $pVal == '!null') {
                        // id = null || id = !null
                        $_rule = $pVal;
                        $_val = null;
                    } else {
                        // id = 10
                        $_rule = 'eq';
                        $_val = $pVal;
                    }
                }
                
                // 检测查询参数是否在ruleWhere内设置
                if (is_array($rVal)) {
                    if (!in_array($_rule, $rVal))
                        continue;
                } elseif ($rVal != '*') {
                    continue;
                }
                
                $format[] = [
                    'rule' => $_rule,
                    'field' => $rKey,
                    'value' => $_val,
                ];
            }
        }
        
        return $format;
    }
    
    /**
     * 预处理查询条件
     */
    private function prepareWhere()
    {
        $formats = $this->formatWhereParams();
        // 归类查询条件
        foreach ($formats as $format) {
            $pairs = explode('.', $format['field']);
            if (count($pairs) === 1) {
                // 主表查询条件
                $this->prepareData = ArrayHelper::merge($this->prepareData, [
                    '_' => [
                        'where' => [$this->ruleConverter($format['rule'], $format['field'], $format['value'])]
                    ]
                ]);
            } else {
                // 关联表
                $fieldName = array_pop($pairs);
                $relation = implode('.', $pairs);
                $this->prepareData = ArrayHelper::merge($this->prepareData, [
                    $relation => ['where' => [$this->ruleConverter($format['rule'], $fieldName, $format['value'])]]
                ]);
            }
        }
    }
    
    /**
     * url规则转换为Yii query格式
     * @param string $rule
     * @param string $field
     * @param string $value
     * @return array
     */
    private function ruleConverter($rule, $field, $value)
    {
        $rules = [
            'eq' => [$field => $value],
            '!eq' => ['NOT', [$field => $value]],
            'like' => ['LIKE', $field, $value],
            'llike' => ['LIKE', $field, '%'.$value, false],
            'rlike' => ['LIKE', $field, $value.'%', false],
            'null' => [$field => null],
            '!null' => ['NOT', [$field => null]],
            'less_than' => ['<', $field, $value],
            'more_than' => ['>', $field, $value],
            'less_than_eq' => ['<=', $field, $value],
            'more_than_eq' => ['>=', $field, $value],
            'in' => ['IN', $field, explode(',', $value)],
            '!in' => ['NOT IN', $field, explode(',', $value)],
        ];
        
        return isset($rules[$rule]) ? $rules[$rule] : [];
    }
    
    /**
     * 格式化排序规则，过滤可用排序
     * @return array
     */
    private function formatSortParams()
    {
        if (!$this->ruleSort || !$this->_extend['_sort']) {
            return [];
        }
        
        $sortArray = explode(',', $this->_extend['_sort']);
        $params = [];
        foreach ($sortArray as $item) {
            $pairs = $this->fetchSort($item);
            if (in_array($pairs['field'], $this->ruleSort)) {
                $params[$pairs['field']] = $pairs['sort'];
            }
        }
        return $params;
    }
    
    
    /**
     * 预处理排序条件，url参数格式：http://localhost?_sort=id.desc,name.asc
     */
    private function prepareOrderBy()
    {
        $params = $this->formatSortParams();
        foreach ($params as $k => $v) {
            $pairs = explode('.', $k);
            if (count($pairs) === 1) {
                $this->prepareData = ArrayHelper::merge($this->prepareData, [
                    '_' => ['orderBy' => [[$k => $v]]]
                ]);
            } else {
                // 关联表查询
                $fieldName = array_pop($pairs);
                $way = implode('.', $pairs);
                $this->prepareData = ArrayHelper::merge($this->prepareData, [
                    $way => ['orderBy' => [[$fieldName => $v]]]
                ]);
            }
        }
    }
    
    /**
     * 提取排序规则
     * @param string $str
     * @return array
     */
    private function fetchSort($str)
    {
        $pairs = explode('.', $str);
        if (in_array(strtoupper(end($pairs)), ['ASC', 'DESC'])) {
            $sortWay = strtoupper(array_pop($pairs));
        } else {
            $sortWay = 'ASC';
        }
        
        $refs = [
            'ASC' => SORT_ASC,
            'DESC' => SORT_DESC,
        ];
        
        return [
            'field' => implode('.', $pairs),
            'sort' => $refs[$sortWay]
        ];
    }
    
    /**
     * 预处理关联表信息
     */
    private function prepareRelated()
    {
        if (!$this->_extend['_expand']) {
            return;
        }
        $withs = explode(',', $this->_extend['_expand']);
        foreach ($withs as $item) {
            $this->prepareData = ArrayHelper::merge($this->prepareData, [
                $item => []
            ]);
        }
    }
    
    /**
     * 应用查询数据
     */
    private function applyData()
    {
        if (isset($this->prepareData['_'])) {
            foreach ($this->prepareData['_'] as $k => $v) {
                if ($k == 'where') {
                    foreach ($v as $item) {
                        $this->_query->andWhere($item);
                    }
                } elseif ($k == 'orderBy') {
                    foreach ($v as $item) {
                        $this->_query->addOrderBy($item);
                    }
                }
            }
        }
        $with = [];
        foreach ($this->prepareData as $k => $params) {
            if ($k == '_') {
                continue;
            }
            if (empty($params)) {
                $with[] = $k;
            } else {
                $with[$k] = function(ActiveQuery $query) use ($params){
                    foreach ($params as $pkey => $pVal) {
                        if ($pkey == 'where') {
                            foreach ($pVal as $item)
                                $query->andWhere($item);
                        } elseif ($pkey == 'orderBy') {
                            foreach ($pVal as $item)
                                $query->addOrderBy($item);
                        }
                    }
                };
            }
        }
        $this->_query->with($with);
    }
}