<?php

declare(strict_types = 1);

namespace  Sweetchuck\GitHooks\Tests\Acceptance;

use Sweetchuck\GitHooks\Test\AcceptanceTester;

class GitHookPostMergeCest
{
    protected function background(AcceptanceTester $I)
    {
        $I->doCreateProjectInstance('basic', 'p-01');
        $I->doGitCommitNewFileWithMessageAndContent('README.md', 'Initial commit', '@todo');
        $I->doGitCheckoutNewBranch('feature-01');
        $I->doGitCommitNewFileWithMessageAndContent('robots.txt', 'Add robots.txt', 'foo');
        $I->doRunGitCheckout('master');
    }

    public function triggerNormalMerge(AcceptanceTester $I)
    {
        $expectedStdError = implode("\n", [
            '>  RoboFile::githookPostMerge is called',
            '>  Squash: 0',
        ]);

        $this->background($I);
        $I->doGitMerge('feature-01', 'Merge feature-01 into master');
        $I->assertStdErrContains($expectedStdError);
    }

    public function triggerSquashMerge(AcceptanceTester $I)
    {
        $expectedStdError = implode("\n", [
            '>  RoboFile::githookPostMerge is called',
            '>  Squash: 1',
        ]);

        $this->background($I);
        $I->doGitMergeSquash('feature-01', 'Merge feature-01 into master');
        $I->assertStdErrContains($expectedStdError);
    }
}
