概述
------
该扩展强化了Yii Restful查询。**支持无限级关联**

在关联查询数据时不是每次都回表查询，而是使用了with加载关联数据，由yii进行数据关系匹配，减少对数据库的访问。

```
例如查询所有user下的所有books，默认yii ar模型需要循环每个user，再调用user->books进行查询，要多次访问数据库。
而使用with急切加载，只需要查询2遍即可获得所有数据。
```
URL 参数支持
---
- 支持无限级关联搜索

url 关键字 | 解释 | 例子
---|---|---
eq | 等于查询 | api.com?id=eq:1 等价于 api.com?id=1
!eq | 不等于 | api.com?id=!eq:1，sql：id != 1
like | 模糊查询| api.com?name=like:数据 ，sql：%数据% 
llike | 左模糊查询| api.com?name=llike:数据 ，sql：%数据 
rlike | 右模糊查询| api.com?name=rlike:数据 ，sql：数据%
null | NULL 查询 | api.com?name=null，sql： name IS NULL
!null | NOT NULL 查询 | api.com?name=!null，sql： name IS NOT NULL
less_than | 小于查询 | api.com?age=less_than:10，sql： age < 10
more_than | 大于查询 | api.com?age=more_than:10，sql： age > 10
less_than_eq | 小于等于查询 | api.com?age=less_than_eq:10，sql： age <= 10
more_than_eq | 大于等于查询 | api.com?age=more_than_eq:10，sql： age >= 10
in | 范围查询 | api.com?id=in:1,2,3，sql： id IN (1,2,3)
!in | 排除范围查询 | api.com?id=！in:1,2,3，sql： id NOT IN (1,2,3)

demo
```
// 查询id为1的用户，以及关联的模糊查询books.name
api.com/users?id=1,books.name=like:自然
```

- 支持无限级关联排序

url关键字 `_sort`
```
// user表id使用asc排序（不穿排序方式默认asc），books.name使用倒序，books.author.age倒序
api.com/users?id=1,books.name=like:自然&_sort=id,books.name.desc,books.author.age.desc
```

- 支持无限级字段过滤

url关键字 `_fields`
```
api.com/users?_fields=id,name,books.name,books.author.name
```

- 指定返回关联数据

url关键字 `_expand`

对应activeRecord内的relations。**以上所有关联查询必须先在此参数中设定，否则无法查询到关联数据**

例如需查询：books.name=like:自然，则必须先通过_expand关联books

```
// 将返回关联的books，以及books内关联的author数据
api.com/users?_expand=books,books.author
```

控制层限制
----
为了防止预期外的查询，可设置相应的规则进行查询限制。

场景：

某些字段不允许like查询，或者只允许某个字段排序（其他字段排序可能造成性能问题）

可通过设置规则，仅对符合规则的url参数进行查询。


```php

public function actionIndex()
{
    ...
    $rules = [
        'where' => [
            'id' => '*', // 允许任意查询条件
            'name' => ['eq'], // 只允许等于查询
            'books.name'=>['like','in'] // 允许like查询和in查询
        ],
        // 只允许以下字段排序
        'sort' => [
            'id',
            'books.id'
        ]
    ];
    ...
}
```

安装
-----
建议通过 [composer](http://getcomposer.org/download/)安装
```
composer require sndwow/yii2-rest-query-helper
```
也可手动安装到：/vendor/sndwow/yii2-rest-query-helper

需要修改vendor/yiisoft/extensions.php，配置中追加
```php
  'sndwow/yii2-rest-query-helper' => 
  array (
    'name' => 'sndwow/yii2-rest-query-helper',
    'version' => '1.0.1.0',
    'alias' => 
    array (
      '@sndwow/rest' => $vendorDir . '/sndwow/yii2-rest-query-helper',
    ),
  ),
```

使用
-----
- 所有关联 AR Model 需继承 sndwow\rest\ActiveRecord 使其强化关联能力

- 在控制器中指定 serializer 为 sndwow\rest\Serializer
```php
class UserController extends \yii\rest\Controller
{
    public $serializer = 'sndwow\rest\Serializer';
    public function actionIndex()
    {
        // 仅在此方法指定
        // $this->serializer = 'sndwow\rest\Serializer';
        
        // 使用User作为主表，也可以使用类名：new QueryHelper('app\models\User')
        $helper = new QueryHelper(User::className())
        
        // 设置查询、排序规则
        $rules = [
            'where' => [
                'id' => '*', // 允许任意查询条件
                'name' => ['eq'], // 只允许等于查询
                'books.name'=>['like','in'] // 允许like查询和in查询
            ],
            // 只允许以下字段排序
            'sort' => [
                'id',
                'books.id'
            ]
        ];
            
        // 应用规则，并返回query实例
        // 实例与普通的query一样，只不过关联了相关数据，例如可以 $query->asArray()->all()
        $query = $helper->build($rules);
        
        // 使用此方式返回可以被sndwow\rest\Serializer进行处理
        // 同时也支持yii model 里的 fields，可自定义返回字段及数据
        return new ActiveDataProvider([
            'query' => $query
        ]);
    }
}
```