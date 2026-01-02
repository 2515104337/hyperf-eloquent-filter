<?php

declare(strict_types=1);

namespace HyperfEloquentFilter\Commands;

use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[Command]
class MakeEloquentFilterCommand extends HyperfCommand
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;

        parent::__construct('make:filter');
    }

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('Create a new Eloquent model filter');
        $this->addArgument('name', InputArgument::REQUIRED, 'Filter name (e.g., UserFilter or User)');
        $this->addOption('model', 'm', InputOption::VALUE_OPTIONAL, 'Generate a filter for the given model');
    }

    public function handle(): void
    {
        $name = $this->input->getArgument('name');

        // 确保以 Filter 结尾
        if (!str_ends_with($name, 'Filter')) {
            $name .= 'Filter';
        }

        $namespace = $this->getNamespace();
        $className = $name;
        $filePath = $this->getPath($name);

        // 检查文件是否已存在
        if (file_exists($filePath)) {
            $this->error("Filter [{$name}] already exists!");
            return;
        }

        // 创建目录
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // 生成内容
        $content = $this->buildClass($namespace, $className);

        // 写入文件
        file_put_contents($filePath, $content);

        $this->info("Filter [{$name}] created successfully.");
        $this->line("File: {$filePath}");
    }

    protected function getNamespace(): string
    {
        if (function_exists('config')) {
            return rtrim(config('eloquent_filter.namespace', 'App\\ModelFilters'), '\\');
        }
        return 'App\\ModelFilters';
    }

    protected function getPath(string $name): string
    {
        $namespace = $this->getNamespace();
        $relativePath = str_replace('\\', '/', str_replace('App\\', '', $namespace));

        return BASE_PATH . '/app/' . $relativePath . '/' . $name . '.php';
    }

    protected function buildClass(string $namespace, string $className): string
    {
        $stub = $this->getStub();

        return str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [$namespace, $className],
            $stub
        );
    }

    protected function getStub(): string
    {
        return <<<'STUB'
<?php

declare(strict_types=1);

namespace {{ namespace }};

use HyperfEloquentFilter\ModelFilter;

class {{ class }} extends ModelFilter
{
    /**
     * 关联模型过滤配置
     * 格式: ['relation' => ['input_key1', 'input_key2']]
     */
    public array $relations = [];

    /**
     * 初始化设置（可选）
     * 在所有过滤器执行前调用
     */
    // public function setup(): void
    // {
    //     // 例如：添加默认排序
    //     // $this->orderBy('created_at', 'desc');
    // }

    /**
     * 示例：按名称过滤
     * 输入键 'name' 会自动调用此方法
     */
    // public function name(string $value): void
    // {
    //     $this->whereLike('name', "%{$value}%");
    // }

    /**
     * 示例：按状态过滤
     * 输入键 'status' 会自动调用此方法
     */
    // public function status(mixed $value): void
    // {
    //     $this->where('status', $value);
    // }
}

STUB;
    }
}
