# Hyperf Eloquent Filter

An elegant way to filter Eloquent Models for Hyperf framework.

This is a Hyperf adaptation of the [tucker-eric/eloquentfilter](https://github.com/Tucker-Eric/EloquentFilter) package.

## Installation

```bash
composer require 2515104337/hyperf-eloquent-filter
```

## Publish Configuration

```bash
php bin/hyperf.php vendor:publish lijian/hyperf-eloquent-filter
```

## Usage

### 1. Add Filterable trait to your model

```php
<?php

namespace App\Model;

use Hyperf\DbConnection\Model\Model;
use HyperfEloquentFilter\Filterable;

class User extends Model
{
    use Filterable;
}
```

### 2. Create a Filter class

Use the artisan command:

```bash
php bin/hyperf.php make:filter UserFilter
```

Or create manually in `app/ModelFilters/UserFilter.php`:

```php
<?php

namespace App\ModelFilters;

use HyperfEloquentFilter\ModelFilter;

class UserFilter extends ModelFilter
{
    public function name(string $value): void
    {
        $this->whereLike('name', "%{$value}%");
    }

    public function status(mixed $value): void
    {
        $this->where('status', $value);
    }

    public function createdAfter(string $date): void
    {
        $this->where('created_at', '>=', $date);
    }
}
```

### 3. Apply the filter in your code

```php
// In your controller or service
$users = User::filter($request->all())->get();

// With pagination
$users = User::filter($request->all())->paginate(15);

// Or use paginateFilter to automatically append filter params to pagination links
$users = User::query()->filter($request->all())->paginateFilter(15);
```

## Filter Method Naming

Filter methods are automatically called based on the input keys:

| Input Key | Filter Method |
|-----------|---------------|
| `name` | `name($value)` |
| `user_name` | `userName($value)` |
| `user_id` | `user($value)` (removes `_id` suffix) |
| `status` | `status($value)` |

## Filtering Relations

You can filter by related models:

```php
class UserFilter extends ModelFilter
{
    public array $relations = [
        'posts' => ['title', 'status'],
    ];
}
```

Or use local closures:

```php
class UserFilter extends ModelFilter
{
    public function role(string $value): void
    {
        $this->related('roles', function ($query) use ($value) {
            $query->where('code', $value);
        });
    }
}
```

## Configuration

Configuration file: `config/autoload/eloquent_filter.php`

```php
return [
    // Default namespace for ModelFilter classes
    'namespace' => 'App\\ModelFilters\\',

    // Default pagination limit
    'paginate_limit' => 15,
];
```

## Custom Filter Class

You can specify a custom filter class in your model:

```php
class User extends Model
{
    use Filterable;

    public function modelFilter(): string
    {
        return \App\CustomFilters\MyUserFilter::class;
    }
}
```

## License

MIT
