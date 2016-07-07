<?php
/**
 * @file
 * Robo task definitions for cheppers/git-hooks-robo.
 */

use Robo\Tasks;
use Symfony\Component\Process\Process;

/**
 * Class RoboFile.
 */
// @codingStandardsIgnoreStart
class RoboFile extends Tasks
    // @codingStandardsIgnoreEnd
{

    /**
     * @var string
     */
    protected $packageVendor = '';

    /**
     * @var string
     */
    protected $packageName = '';

    /**
     * The "bin-dir" configured in composer.json.
     *
     * @var string
     */
    protected $binDir = 'vendor/bin';

    /**
     * @var string
     */
    protected $gitExecutable = 'git';

    /**
     * @var string[]
     */
    protected $filesToDeploy = [
        'applypatch-msg' => ['base_mask' => 0777],
        'commit-msg' => ['base_mask' => 0777],
        'post-applypatch' => ['base_mask' => 0777],
        'post-checkout' => ['base_mask' => 0777],
        'post-commit' => ['base_mask' => 0777],
        'post-merge' => ['base_mask' => 0777],
        'post-receive' => ['base_mask' => 0666],
        'post-rewrite' => ['base_mask' => 0777],
        'post-update' => ['base_mask' => 0777],
        'pre-applypatch' => ['base_mask' => 0777],
        'pre-auto-gc' => ['base_mask' => 0777],
        'pre-commit' => ['base_mask' => 0777],
        'pre-push' => ['base_mask' => 0777],
        'pre-rebase' => ['base_mask' => 0777],
        'pre-receive' => ['base_mask' => 0666],
        'prepare-commit-msg' => ['base_mask' => 0777],
        'push-to-checkout' => ['base_mask' => 0777],
        'update' => ['base_mask' => 0777],
    ];

    /**
     * RoboFile constructor.
     */
    public function __construct()
    {
        $package = json_decode(file_get_contents(__DIR__ . '/composer.json'), true);
        list($this->packageVendor, $this->packageName) = explode('/', $package['name']);

        if (!empty($package['config']['bin-dir'])) {
            $this->binDir = $package['config']['bin-dir'];
        }
    }

    /**
     * Create release tar balls.
     *
     * @param string $version
     *   Example: 1.0.0
     *
     * @throws \Exception
     */
    public function releaseCreate($version)
    {
        if (!$this->isValidVersionNumber($version)) {
            throw new \Exception('Invalid version number', 1);
        }

        $this->stopOnFail(true);

        /** @var \Robo\Collection\Collection $collection */
        $collection = $this->collection();

        $collection->add($this
            ->taskFilesystemStack()
            ->remove('release'));

        $cmd = sprintf(
            'composer archive --format=%s --dir=%s --file=%s',
            escapeshellarg('zip'),
            escapeshellarg('release'),
            escapeshellarg("v{$version}")
        );
        $collection->add($this->taskExec($cmd));

        $collection->add($this
            ->taskExtract("release/v{$version}.zip")
            ->to("release/{$this->packageName}-{$version}"));

        $collection->add($this
            ->taskFilesystemStack()
            ->remove("release/v{$version}.zip"));

        $fs_stack_chmod = $this->taskFilesystemStack();
        foreach ($this->filesToDeploy as $file_name => $file_meta) {
            $fs_stack_chmod->chmod(
                "release/{$this->packageName}-{$version}/$file_name",
                $file_meta['base_mask'],
                0022
            );
        }
        $collection->add($fs_stack_chmod);

        foreach (['tar.gz', 'zip'] as $extension) {
            $collection->add($this
                ->taskPack("release/{$this->packageVendor}-{$this->packageName}-{$version}.$extension")
                ->addDir("{$this->packageName}-{$version}", "release/{$this->packageName}-{$version}"));
        }

        $collection->run();
    }

    public function deployGitHooks()
    {
        $this->stopOnFail(true);
        $task = $this->getTaskDeployGitHooks();
        if ($task) {
            $task->run();
        }
    }

    public function test()
    {
        $this->stopOnFail(true);

        /** @var \Robo\Collection\Collection $c */
        $c = $this->collection();
        $c
            ->add($this->getTaskBehatRun())
            ->run();
    }

    public function behat()
    {
        $this->stopOnFail(true);

        $this
            ->getTaskBehatRun()
            ->run();
    }

    public function composerValidate()
    {
        $this->stopOnFail(true);
        $this
            ->getTaskComposerValidate()
            ->run();
    }

    public function lint()
    {
        $this->stopOnFail(true);

        /** @var \Robo\Collection\Collection $c */
        $c = $this->collection();
        $c
            ->add($this->getTaskPhpcsLint())
            ->add($this->getTaskComposerValidate())
            ->run();
    }

    public function lintPhpcs()
    {
        $this
            ->getTaskPhpcsLint()
            ->run();
    }

    public function githookPreCommit()
    {
        $this->lint();
    }

    /**
     * @return \Robo\Task\Base\Exec
     */
    protected function getTaskPhpcsLint()
    {
        $cmd_pattern = '%s --standard=%s --ignore=%s %s %s %s';
        $cmd_args = [
            escapeshellcmd("{$this->binDir}/phpcs"),
            escapeshellarg('PSR2'),
            escapeshellarg('fixtures/project-template/*/vendor/'),
            escapeshellarg('features/bootstrap/'),
            escapeshellarg('fixtures/project-template/'),
            escapeshellarg('RoboFile.php'),
        ];

        return $this->taskExec(vsprintf($cmd_pattern, $cmd_args));
    }

    /**
     * @return \Robo\Task\Base\Exec
     */
    protected function getTaskComposerValidate()
    {
        return $this->taskExec('composer validate');
    }

    /**
     * @return \Robo\Task\Filesystem\FilesystemStack|null
     */
    protected function getTaskDeployGitHooks()
    {
        $current_dir = realpath(getcwd());
        $repo_type = $this->getGitRepoType();
        if ($repo_type === null) {
            // This directory is not tracked by Git.
            return null;
        }

        $git_dir = $this->getGitDir();
        if (!($repo_type === 'bare' && strpos($current_dir, $git_dir) === 0)
            && !($repo_type === 'not-bare' && file_exists("$current_dir/.git"))
        ) {
            // Git directory cannot be detected 100%.
            return null;
        }

        /** @var \League\Container\Container $container */
        $container = $this->getContainer();

        /** @var \Robo\Task\Filesystem\FilesystemStack $fsStack */
        $fsStack = $container->get('taskFilesystemStack');

        $git_dir = preg_replace('@^' . preg_quote("$current_dir/", '@') . '@', './', $git_dir);
        foreach ($this->filesToDeploy as $file_name => $file_meta) {
            $dst = "$git_dir/hooks/$file_name";
            $fsStack->copy($file_name, $dst);
            $fsStack->chmod($dst, $file_meta['base_mask'], umask());
        }

        return $fsStack;
    }

    /**
     * @return \Robo\Task\Base\Exec
     */
    protected function getTaskBehatRun()
    {
        $cmd = sprintf(
            '%s --colors --strict',
            escapeshellcmd("{$this->binDir}/behat")
        );

        return  $this->taskExec($cmd);
    }

    /**
     * @return string|null
     */
    protected function getGitRepoType()
    {
        $cmd = sprintf(
            '%s rev-parse --is-bare-repository',
            escapeshellcmd($this->gitExecutable)
        );

        $process = new Process($cmd);
        $exit_code = $process->run();
        if ($exit_code) {
            return null;
        }

        return trim($process->getOutput()) === 'true' ? 'bare' : 'not-bare';
    }

    /**
     * @return string|null
     */
    protected function getGitDir()
    {
        $cmd = sprintf(
            '%s rev-parse --git-dir',
            escapeshellcmd($this->gitExecutable)
        );

        $process = new Process($cmd);
        $exit_code = $process->run();
        if ($exit_code !== 0) {
            return null;
        }

        return realpath(rtrim($process->getOutput(), "\n"));
    }

    /**
     * @param string $version
     *
     * @return bool
     */
    protected function isValidVersionNumber($version)
    {
        return preg_match('/^\d+\.\d+\.\d+(-(alpha|beta|rc)\d+){0,1}$/', $version);
    }
}
