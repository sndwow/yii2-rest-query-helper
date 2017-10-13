<?php
/**
 *
 * author: Wei Chang Yue
 * date: 2017/10/08 14:07
 * version: 1.0
 */


namespace sndwow\rest;


use yii\db\ActiveQuery;
use Yii;
use yii\helpers\ArrayHelper;

class RestQueryHelper
{
    /*
     * 允许的查询规则，例如
     * [
     *  'id'=>['eq','!eq'], // 仅支持等于和不等于查询
     *  'name'=>'*', //支持所有查询类型
     *  'store.id'=>['eq'] // 关联表并查询id
     * ]
     * 若指定关联表，将会自动追加关联表
     */
    public $whereRules = [];
    /*
     * 支持的排序的字段，例如['id','create_time','store.id']
     * 支持关联子表排序，只支持已经设定关联的表后才可排序
     */
    public $sortRules = [];
    /*
     * 允许使用的关联表，例如['store','store.storePos']
     * */
    public $withRules = [];
    /*
     * 接口允许每页的最大数据条数
     * */
    public $maxPerPage = 200;
    
    /*
     * 允许搜索的扩展字段，例如['storeInfo'=>'store','storePos'=>'store.storePos']
    */
    // public $expand=[];
    
    /* 支持的查询条件 */
    public $conditions = [
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
    public $queryParams = [];
    // 扩展查询参数
    public $paramSort = null;     // 排序
    public $paramFields = null;   // 过滤字段
    public $paramPage = 1;    // 页码
    public $paramPerPage = 20; // 每页数量
    public $paramPaging = null;   // 关联分页
    public $paramWith = null; // 关联哪些表
    
    
    /* @var ActiveQuery $query */
    private $_query = null;
    
    private $_prepareData = [];
    
    public function __construct($modelClass)
    {
        /* @var $modelClass \yii\db\BaseActiveRecord */
        $this->_query = $modelClass::find();
    }
    
    public function initParams($method = 'get')
    {
        $paramString = strtolower($method) == 'get' ? Yii::$app->request->queryString : Yii::$app->request->rawBody;
        $params = [];
        foreach (explode('&', $paramString) as $pair) {
            $tmp = explode('=', $pair, 2);
            if (count($tmp) === 2) {
                $params[$tmp[0]] = urldecode($tmp[1]);
            }
        }
        
        if (isset($params['_sort'])) {
            $this->paramSort = $params['_sort'];
        }
        if (isset($params['_fields'])) {
            $this->paramFields = $params['_fields'];
        }
        if (isset($params['_page'])) {
            $this->paramPage = $params['_page'];
        }
        if (isset($params['_perPage'])) {
            $this->paramPerPage = $params['_perPage'];
        }
        if (isset($params['_paging'])) {
            $this->paramPaging = $params['_paging'];
        }
        if (isset($params['_with'])) {
            $this->paramWith = $params['_with'];
        }
        
        $exclude = ['_sort', '_fields', '_page', '_perPage', '_paging', '_with'];
        
        // 其他查询参数
        foreach ($exclude as $item) {
            unset($params[$item]);
        }
        $this->queryParams = $params;
        return $this;
    }
    
    public function createQuery()
    {
        // 预处理关联查询
        $this->prepareWith();
        // 预处理搜索条件
        $this->prepareWhere();
        // 预处理排序条件
        $this->prepareOrderBy();
        // 预处理分页
        $this->preparePaging();
        // 应用查找参数
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
        foreach ($this->whereRules as $rKey => $rVal) {
            foreach ($this->queryParams as $pKey => $pVal) {
                if ($pKey != $rKey)
                    continue;
                // 拆解参数值
                $value = explode(':', $pVal, 2);
                if (count($value) === 2) {
                    if (in_array($value[0], $this->conditions)) {
                        $_rule = $value[0];
                        $_val = $value[1];
                    } else {
                        $_rule = 'eq';
                        $_val = $pVal;
                    }
                } else {
                    if ($pVal == 'null' || $pVal == '!null') {
                        $_rule = $pVal;
                        $_val = null;
                    } else {
                        $_rule = 'eq';
                        $_val = $pVal;
                    }
                }
                
                // 检测规则是否可用
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
        foreach ($formats as $format) {
            $pairs = explode('.', $format['field']);
            if (count($pairs) === 1) {
                $this->_prepareData = ArrayHelper::merge($this->_prepareData, [
                    '_' => [
                        'where' => [$this->ruleConverter($format['rule'], $format['field'], $format['value'])]
                    ]
                ]);
            } else {
                // 关联表
                $fieldName = array_pop($pairs);
                $relation = implode('.', $pairs);
                $this->_prepareData = ArrayHelper::merge($this->_prepareData, [
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
        if (!$this->sortRules || !$this->paramSort) {
            return [];
        }
        
        $sortArray = explode(',', $this->paramSort);
        $params = [];
        foreach ($sortArray as $item) {
            $pairs = $this->fetchSort($item);
            if (in_array($pairs['field'], $this->sortRules)) {
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
                $this->_prepareData = ArrayHelper::merge($this->_prepareData, [
                    '_' => ['orderBy' => [[$k => $v]]]
                ]);
            } else {
                // 关联表查询
                $fieldName = array_pop($pairs);
                $way = implode('.', $pairs);
                $this->_prepareData = ArrayHelper::merge($this->_prepareData, [
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
     * 预处理分页信息
     */
    private function preparePaging()
    {
        // 顶级资源分页
        $page = (int)Yii::$app->request->get('_page', 1);
        $limit = (int)Yii::$app->request->get('_perPage', 20);
        if ($limit > $this->maxPerPage) {
            $limit = $this->maxPerPage;
        }
        $page = ($page - 1) < 0 ? 0 : $page - 1;
        $offset = $page * $limit;
        $this->_prepareData = ArrayHelper::merge($this->_prepareData, [
            '_' => [
                'offset' => $offset,
                'limit' => $limit,
            ]
        ]);
        
        // 关联资源分页
        if (!$this->paramPaging) {
            return;
        }
        $paging = explode(',', $this->paramPaging);
        
        foreach ($paging as $item) {
            if (!preg_match('/.*_\d+_\d+/', $item)) {
                continue;
            }
            $tmp = explode('_', $item);
            // 排序不允许主动设置关联表信息，只能追加
            if (!isset($this->_prepareData[$tmp[0]])) {
                continue;
            }
            $limit = $tmp[2];
            if ($limit > $this->maxPerPage) {
                $limit = $this->maxPerPage;
            }
            $page = ($tmp[1] - 1) < 0 ? 0 : $tmp[1] - 1;
            $offset = $page * $limit;
            $this->_prepareData = ArrayHelper::merge($this->_prepareData, [
                $tmp[0] => [
                    'offset' => $offset,
                    'limit' => $limit,
                ]
            ]);
        }
    }
    
    /**
     * 预处理with查询
     */
    private function prepareWith()
    {
        if (!$this->withRules) {
            return;
        }
        if (!$this->paramWith) {
            return;
        }
        $withs = explode(',', $this->paramWith);
        foreach ($withs as $item) {
            if (in_array($item, $this->withRules)) {
                $this->_prepareData = ArrayHelper::merge($this->_prepareData, [
                    $item => []
                ]);
            }
        }
    }
    
    /**
     * 应用查询数据
     */
    private function applyData()
    {
        if (isset($this->_prepareData['_'])) {
            foreach ($this->_prepareData['_'] as $k => $v) {
                if ($k == 'where') {
                    foreach ($v as $item)
                        $this->_query->andWhere($item);
                } elseif ($k == 'limit') {
                    $this->_query->limit($v);
                } elseif ($k == 'offset') {
                    $this->_query->offset($v);
                } elseif ($k == 'orderBy') {
                    foreach ($v as $item)
                        $this->_query->addOrderBy($item);
                }
            }
        }
        
        foreach ($this->_prepareData as $k => $params) {
            if ($k == '_') {
                continue;
            }
            if (empty($params)) {
                $this->_query->with($k);
            } else {
                $this->_query->with([
                    $k => function(ActiveQuery $query) use ($params){
                        foreach ($params as $pkey => $pVal) {
                            if ($pkey == 'where') {
                                foreach ($pVal as $item)
                                    $query->andWhere($item);
                            } elseif ($pkey == 'limit') {
                                $query->limit($pVal);
                            } elseif ($pkey == 'offset') {
                                $query->offset($pVal);
                            } elseif ($pkey == 'orderBy') {
                                foreach ($pVal as $item)
                                    $query->addOrderBy($item);
                            }
                        }
                    }
                ]);
            }
        }
    }
    
    
}