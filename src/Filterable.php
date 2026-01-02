<?php

declare(strict_types=1);

namespace HyperfEloquentFilter;

use Hyperf\Database\Model\Builder;
use Hyperf\Stringable\Str;

/**
 * Model Filter Trait
 * 为 Model 添加 filter 查询能力
 *
 * @method static Builder filter(array $input = [], string|null $filter = null)
 * @method static Builder paginateFilter(int|null $perPage = null, array $columns = ['*'], string $pageName = 'page', int|null $page = null)
 * @method static Builder simplePaginateFilter(int|null $perPage = null, array $columns = ['*'], string $pageName = 'page', int|null $page = null)
 * @method static Builder whereLike(string $column, mixed $value, string $boolean = 'and')
 * @method static Builder whereBeginsWith(string $column, mixed $value, string $boolean = 'and')
 * @method static Builder whereEndsWith(string $column, mixed $value, string $boolean = 'and')
 */
trait Filterable
{
    /**
     * 用于过滤的输入数组
     */
    protected array $filtered = [];

    /**
     * 创建 filter 本地作用域
     *
     * @param mixed $query
     * @param array $input
     * @param string|null $filter
     * @return Builder
     */
    public function scopeFilter($query, array $input = [], ?string $filter = null): Builder
    {
        // 获取当前 Model 的 Filter 类
        if ($filter === null) {
            $filter = $this->getModelFilterClass();
        }

        // 创建 filter 实例
        $modelFilter = new $filter($query, $input);

        // 设置过滤后的输入
        $this->filtered = $modelFilter->input();

        // 返回过滤后的查询
        return $modelFilter->handle();
    }

    /**
     * 分页并附带查询参数
     *
     * @param mixed $query
     * @param int|null $perPage
     * @param array $columns
     * @param string $pageName
     * @param int|null $page
     * @return mixed
     */
    public function scopePaginateFilter($query, ?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null)
    {
        $perPage = $perPage ?: $this->getFilterPaginateLimit();
        $paginator = $query->paginate($perPage, $columns, $pageName, $page);
        $paginator->appends($this->filtered);

        return $paginator;
    }

    /**
     * 简单分页并附带查询参数
     *
     * @param mixed $query
     * @param int|null $perPage
     * @param array $columns
     * @param string $pageName
     * @param int|null $page
     * @return mixed
     */
    public function scopeSimplePaginateFilter($query, ?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null)
    {
        $perPage = $perPage ?: $this->getFilterPaginateLimit();
        $paginator = $query->simplePaginate($perPage, $columns, $pageName, $page);
        $paginator->appends($this->filtered);

        return $paginator;
    }

    /**
     * 获取 Filter 类名
     */
    public function provideFilter(?string $filter = null): string
    {
        if ($filter === null) {
            // 获取类名的基础名称（不含命名空间）
            $className = Str::afterLast(static::class, '\\');
            // 获取配置的命名空间
            $namespace = $this->getFilterNamespace();
            $filter = $namespace . $className . 'Filter';
        }

        return $filter;
    }

    /**
     * 获取当前 Model 的 Filter 类
     */
    public function getModelFilterClass(): string
    {
        return method_exists($this, 'modelFilter') ? $this->modelFilter() : $this->provideFilter();
    }

    /**
     * 获取 Filter 命名空间
     */
    protected function getFilterNamespace(): string
    {
        if (function_exists('config')) {
            return config('eloquent_filter.namespace', 'App\\ModelFilters\\');
        }
        return 'App\\ModelFilters\\';
    }

    /**
     * 获取分页限制
     */
    protected function getFilterPaginateLimit(): int
    {
        if (function_exists('config')) {
            return (int) config('eloquent_filter.paginate_limit', 15);
        }
        return 15;
    }

    /**
     * WHERE $column LIKE %$value% 查询
     *
     * @param mixed $query
     * @param string $column
     * @param mixed $value
     * @param string $boolean
     * @return Builder
     */
    public function scopeWhereLike($query, string $column, $value, string $boolean = 'and'): Builder
    {
        return $query->where($column, 'LIKE', "%{$value}%", $boolean);
    }

    /**
     * WHERE $column LIKE $value% 查询
     *
     * @param mixed $query
     * @param string $column
     * @param mixed $value
     * @param string $boolean
     * @return Builder
     */
    public function scopeWhereBeginsWith($query, string $column, $value, string $boolean = 'and'): Builder
    {
        return $query->where($column, 'LIKE', "{$value}%", $boolean);
    }

    /**
     * WHERE $column LIKE %$value 查询
     *
     * @param mixed $query
     * @param string $column
     * @param mixed $value
     * @param string $boolean
     * @return Builder
     */
    public function scopeWhereEndsWith($query, string $column, $value, string $boolean = 'and'): Builder
    {
        return $query->where($column, 'LIKE', "%{$value}", $boolean);
    }
}
