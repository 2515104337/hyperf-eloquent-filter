<?php

declare(strict_types=1);

namespace HyperfEloquentFilter;

use Hyperf\Database\Model\Builder as QueryBuilder;
use Hyperf\Stringable\Str;

/**
 * Model Filter 基类
 * 用于构建可复用的查询过滤器
 *
 * @mixin QueryBuilder
 */
abstract class ModelFilter
{
    /**
     * 关联模型的过滤配置
     * 格式: [relatedModel => [input_key1, input_key2]]
     */
    public array $relations = [];

    /**
     * 本地定义的关联过滤闭包
     * 格式: ['relation' => [\Closure, \Closure]]
     */
    protected array $localRelatedFilters = [];

    /**
     * 所有关联配置（本地 + 关联模型 Filter）
     */
    protected array $allRelations = [];

    /**
     * 黑名单方法（不允许调用）
     */
    protected array $blacklist = [];

    /**
     * 是否允许空过滤值（空字符串、空数组、null）
     */
    protected bool $allowedEmptyFilters = false;

    /**
     * 输入数组
     */
    protected array $input;

    /**
     * 查询构建器
     */
    protected QueryBuilder $query;

    /**
     * 是否移除输入键末尾的 _id
     */
    protected bool $drop_id = true;

    /**
     * 是否将输入键转换为驼峰命名
     * 例如: my_awesome_key 会转换为 myAwesomeKey($value)
     */
    protected bool $camel_cased_methods = true;

    /**
     * 是否启用关联查询
     */
    protected bool $relationsEnabled;

    /**
     * 已 join 的表
     */
    private ?array $_joinedTables = null;

    public function __construct(QueryBuilder $query, array $input = [], bool $relationsEnabled = true)
    {
        $this->query = $query;
        $this->input = $this->allowedEmptyFilters ? $input : $this->removeEmptyInput($input);
        $this->relationsEnabled = $relationsEnabled;
    }

    /**
     * 魔术方法：将未定义的方法调用代理到查询构建器
     */
    public function __call(string $method, array $args): mixed
    {
        $resp = call_user_func_array([$this->query, $method], $args);

        // 如果返回的是查询构建器，返回 $this 保持链式调用
        return $resp instanceof QueryBuilder ? $this : $resp;
    }

    /**
     * 移除空输入值
     */
    public function removeEmptyInput(array $input): array
    {
        $filterableInput = [];

        foreach ($input as $key => $val) {
            if ($this->includeFilterInput($key, $val)) {
                $filterableInput[$key] = $val;
            }
        }

        return $filterableInput;
    }

    /**
     * 处理所有过滤器
     */
    public function handle(): QueryBuilder
    {
        // 调用 setup 方法（如果存在）
        if (method_exists($this, 'setup')) {
            $this->setup();
        }

        // 运行输入过滤
        $this->filterInput();

        // 设置关联查询约束
        $this->filterRelations();

        return $this->query;
    }

    /**
     * 定义一个本地关联过滤方法
     */
    public function addRelated(string $relation, \Closure $closure): static
    {
        $this->localRelatedFilters[$relation][] = $closure;

        return $this;
    }

    /**
     * 添加关联约束
     */
    public function related(string $relation, mixed $column, ?string $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        if ($column instanceof \Closure) {
            return $this->addRelated($relation, $column);
        }

        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        return $this->addRelated($relation, function ($query) use ($column, $operator, $value, $boolean) {
            return $query->where($column, $operator, $value, $boolean);
        });
    }

    /**
     * 根据输入键获取过滤方法名
     */
    public function getFilterMethod(string $key): string
    {
        // 移除方法名中的 '.' 字符
        $methodName = str_replace('.', '', $this->drop_id ? preg_replace('/^(.*)_id$/', '$1', $key) : $key);

        // 转换为驼峰命名
        return $this->camel_cased_methods ? Str::camel($methodName) : $methodName;
    }

    /**
     * 根据输入执行过滤
     */
    public function filterInput(): void
    {
        foreach ($this->input as $key => $val) {
            $method = $this->getFilterMethod($key);

            if ($this->methodIsCallable($method)) {
                $this->{$method}($val);
            }
        }
    }

    /**
     * 过滤关联模型
     */
    public function filterRelations(): static
    {
        if ($this->relationsEnabled()) {
            foreach ($this->getAllRelations() as $related => $filterable) {
                if (count($filterable) > 0) {
                    if ($this->relationIsJoined($related)) {
                        $this->filterJoinedRelation($related);
                    } else {
                        $this->filterUnjoinedRelation($related);
                    }
                }
            }
        }

        return $this;
    }

    /**
     * 获取所有关联配置
     */
    public function getAllRelations(): array
    {
        if (count($this->allRelations) === 0) {
            $allRelations = array_merge(array_keys($this->relations), array_keys($this->localRelatedFilters));

            foreach ($allRelations as $related) {
                $this->allRelations[$related] = array_merge(
                    $this->getLocalRelation($related),
                    $this->getRelatedFilterInput($related)
                );
            }
        }

        return $this->allRelations;
    }

    /**
     * 获取关联约束
     */
    public function getRelationConstraints(string $relation): array
    {
        return $this->allRelations[$relation] ?? [];
    }

    /**
     * 调用关联的 setup 方法
     */
    public function callRelatedLocalSetup(string $related, $query): void
    {
        $method = Str::camel($related) . 'Setup';
        if (method_exists($this, $method)) {
            $this->{$method}($query);
        }
    }

    /**
     * 过滤已 join 的关联
     */
    public function filterJoinedRelation(string $related): void
    {
        $this->callRelatedLocalSetup($related, $this->query);

        foreach ($this->getLocalRelation($related) as $closure) {
            $closure($this->query);
        }

        $relatedFilterInput = $this->getRelatedFilterInput($related);
        if (count($relatedFilterInput) > 0) {
            $filterClass = $this->getRelatedFilter($related);
            (new $filterClass($this->query, $relatedFilterInput, false))->handle();
        }
    }

    /**
     * 获取已 join 的表
     */
    public function getJoinedTables(): array
    {
        $joins = [];
        $queryJoins = $this->query->getQuery()->joins;

        if (is_array($queryJoins)) {
            $joins = array_map(function ($join) {
                return $join->table;
            }, $queryJoins);
        }

        return $joins;
    }

    /**
     * 检查关联表是否已 join
     */
    public function relationIsJoined(string $relation): bool
    {
        if ($this->_joinedTables === null) {
            $this->_joinedTables = $this->getJoinedTables();
        }

        return in_array($this->getRelatedTable($relation), $this->_joinedTables, true);
    }

    /**
     * 获取关联模型实例
     */
    public function getRelatedModel(string $relation): mixed
    {
        if (strpos($relation, '.') !== false) {
            return $this->getNestedRelatedModel($relation);
        }

        return $this->query->getModel()->{$relation}()->getRelated();
    }

    /**
     * 获取嵌套关联模型
     */
    protected function getNestedRelatedModel(string $relationString): mixed
    {
        $parts = explode('.', $relationString);
        $related = $this->query->getModel();

        do {
            $relation = array_shift($parts);
            $related = $related->{$relation}()->getRelated();
        } while (!empty($parts));

        return $related;
    }

    /**
     * 获取关联表名
     */
    public function getRelatedTable(string $relation): string
    {
        return $this->getRelatedModel($relation)->getTable();
    }

    /**
     * 获取关联模型的 Filter 类
     */
    public function getRelatedFilter(string $relation): string
    {
        return $this->getRelatedModel($relation)->getModelFilterClass();
    }

    /**
     * 过滤未 join 的关联
     */
    public function filterUnjoinedRelation(string $related): void
    {
        $this->query->whereHas($related, function ($q) use ($related) {
            $this->callRelatedLocalSetup($related, $q);

            foreach ($this->getLocalRelation($related) as $closure) {
                $closure($q);
            }

            $filterableRelated = $this->getRelatedFilterInput($related);
            if (count($filterableRelated) > 0) {
                $q->filter($filterableRelated);
            }

            return $q;
        });
    }

    /**
     * 获取传递给关联 Filter 的输入
     */
    public function getRelatedFilterInput(string $related): array
    {
        $output = [];

        if (array_key_exists($related, $this->relations)) {
            foreach ((array) $this->relations[$related] as $alias => $name) {
                $keyName = is_string($alias) ? $alias : $name;

                if (array_key_exists($keyName, $this->input)) {
                    $keyValue = $this->input[$keyName];

                    if ($this->includeFilterInput($keyName, $keyValue)) {
                        $output[$name] = $keyValue;
                    }
                }
            }
        }

        return $output;
    }

    /**
     * 检查关联是否可过滤
     */
    public function relationIsFilterable(string $relation): bool
    {
        return $this->relationUsesFilter($relation) || $this->relationIsLocal($relation);
    }

    /**
     * 检查关联是否使用 Filter
     */
    public function relationUsesFilter(string $related): bool
    {
        return count($this->getRelatedFilterInput($related)) > 0;
    }

    /**
     * 检查是否有本地定义的关联
     */
    public function relationIsLocal(string $related): bool
    {
        return count($this->getLocalRelation($related)) > 0;
    }

    /**
     * 获取本地关联配置
     */
    public function getLocalRelation(string $related): array
    {
        return $this->localRelatedFilters[$related] ?? [];
    }

    /**
     * 获取输入值
     */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->input;
        }

        return $this->input[$key] ?? $default;
    }

    /**
     * 禁用关联查询
     */
    public function disableRelations(): static
    {
        $this->relationsEnabled = false;

        return $this;
    }

    /**
     * 启用关联查询
     */
    public function enableRelations(): static
    {
        $this->relationsEnabled = true;

        return $this;
    }

    /**
     * 检查关联查询是否启用
     */
    public function relationsEnabled(): bool
    {
        return $this->relationsEnabled;
    }

    /**
     * 添加过滤值
     */
    public function push(mixed $key, mixed $value = null): void
    {
        if (is_array($key)) {
            $this->input = array_merge($this->input, $key);
        } else {
            $this->input[$key] = $value;
        }
    }

    /**
     * 设置是否移除 _id 后缀
     */
    public function dropIdSuffix(?bool $bool = null): bool
    {
        if ($bool === null) {
            return $this->drop_id;
        }

        return $this->drop_id = $bool;
    }

    /**
     * 设置是否转换为驼峰命名
     */
    public function convertToCamelCasedMethods(?bool $bool = null): bool
    {
        if ($bool === null) {
            return $this->camel_cased_methods;
        }

        return $this->camel_cased_methods = $bool;
    }

    /**
     * 添加方法到黑名单
     */
    public function blacklistMethod(string $method): static
    {
        $this->blacklist[] = $method;

        return $this;
    }

    /**
     * 从黑名单移除方法
     */
    public function whitelistMethod(string $method): static
    {
        $this->blacklist = array_filter($this->blacklist, function ($name) use ($method) {
            return $name !== $method;
        });

        return $this;
    }

    /**
     * 检查方法是否在黑名单
     */
    public function methodIsBlacklisted(string $method): bool
    {
        return in_array($method, $this->blacklist, true);
    }

    /**
     * 检查方法是否可调用
     */
    public function methodIsCallable(string $method): bool
    {
        return !$this->methodIsBlacklisted($method) &&
            method_exists($this, $method) &&
            !method_exists(ModelFilter::class, $method);
    }

    /**
     * 判断输入是否应该被过滤
     */
    protected function includeFilterInput(string $key, mixed $value): bool
    {
        return $value !== '' && $value !== null && !(is_array($value) && empty($value));
    }
}
