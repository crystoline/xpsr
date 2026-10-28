<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Tests\Fixtures;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** A temporary project directory with a migrations folder, removed after the test. */
final class Workspace
{
    public readonly string $root;
    public readonly string $migrations;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/xpsr-database-' . bin2hex(random_bytes(6));
        $this->migrations = $this->root . '/migrations';
        mkdir($this->migrations, 0777, true);
    }

    /**
     * Write a migration file. $up and $down are PHP statements using $db.
     */
    public function migration(string $name, string $up, string $down = '', string $extra = ''): string
    {
        $downMethod = $down === '' ? '' : "\n    public function down(Connection \$db): void\n    {\n        {$down}\n    }\n";
        $file = "{$this->migrations}/{$name}.php";
        file_put_contents($file, <<<PHP
<?php

declare(strict_types=1);

use Crystoline\\Xpsr\\Database\\Migration\\Migration;
use Doctrine\\DBAL\\Connection;

return new class extends Migration {
    {$extra}
    public function up(Connection \$db): void
    {
        {$up}
    }
{$downMethod}};
PHP);

        return $file;
    }

    public function remove(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }
}
