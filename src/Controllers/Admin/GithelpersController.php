<?php namespace Sanatorium\Githelper\Controllers\Admin;

use Platform\Access\Controllers\AdminController;
use Sanatorium\Githelper\Repositories\Githelper\GithelperRepositoryInterface;
use Cache;

class GithelpersController extends AdminController
{

    /**
     * Display a listing of githelper.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $repos = $this->getRepositories();

        return view('sanatorium/githelper::index', compact('repos'));
    }

    public function getRepositories($full = true)
    {
        // Output repos
        $repos = [];

        // Paths to find git repositories (without trailing slash)
        $paths = config('sanatorium-githelper.paths');

        foreach ( $paths as $path )
        {
            $dirs = array_filter(glob(base_path($path) . '/*'), 'is_dir');

            foreach ( $dirs as $dir )
            {

                $is_repo = false;

                if ( file_exists($dir . '/.git') )
                    $is_repo = true;

                if ( $is_repo )
                {

                    if ( $full )
                        $repo = $this->getRepoInformation($dir);
                    else
                        $repo = $dir;

                    $repos[ $dir ] = $repo;

                }

            }

        }

        ksort($repos);

        return $repos;
    }

    /**
     * Increase version in tag (f.e. 0.1.0 -> 0.1.1)
     * and push to remote origin master
     * used: MAJOR.MINOR.PATCH (1.0.0)
     *
     * @return mixed
     */
    public function tagpush($type = 'patch', $dir = null, $new_tag = null, $message = null, $patch_version_limit = 100, $minor_version_limit = 10)
    {
        if ( is_null($dir) )
            $dir = request()->get('dir');

        $repo = $this->getRepoInformation($dir);

        // If new tag is not specified, figure out ourselves
        if ( is_null($new_tag) )
        {
            $version_format = '%d.%d.%d';

            $versions = explode('.', $repo['last_tag']);

            array_walk($versions, function (&$item)
            {
                intval($item);
            });

            // hotfix if version number is N.0 instead of N.0.0
            if ( !isset($versions[2]) )
                $versions[2] = 0;

            list($major, $minor, $patch) = $versions;

            if ( $patch < $patch_version_limit && $type == 'patch' )
            {
                $patch ++;
                $new_tag = sprintf($version_format, $major, $minor, $patch);
            } else
            {
                if ( $minor < $minor_version_limit && ($type == 'minor' || $type == 'patch') )
                {
                    $patch = 0;
                    $minor ++;
                    $new_tag = sprintf($version_format, $major, $minor, $patch);
                } else
                {
                    $patch = 0;
                    $minor = 0;
                    $major ++;
                    $new_tag = sprintf($version_format, $major, $minor, $patch);
                }

            }
        }

        $this->updateExtensionVersion($dir, $new_tag);

        if ( is_null($message) )
            $message = 'automatic commit';

        exec('git -C "' . $dir . '" add --all');
        exec('git -C "' . $dir . '" commit -a -m "'.$message.'"');
        exec('git -C "' . $dir . '" tag ' . $new_tag);
        exec('git -C "' . $dir . '" push -u origin master --tags');

        $this->flushCache($dir);

        // @todo - catch exceptions
        $this->alerts->success(trans('sanatorium/githelper::common.messages.tagpush.success', ['tag' => $new_tag]));

        return redirect()->back();
    }

    /**
     * @param      $dir   Target folder to get information from
     * @param bool $cache Take information from cache? (use false to force up to date)
     * @return array [dir => target directory, basename => directory basename, changed_files => number of git tracked
     *               changed files, last_tag => last git tag, has_readme => bool (true = has README.md file)]
     */
    public function getRepoInformation($dir, $cache = true)
    {
        if ( $cache )
        {
            $repo = Cache::rememberForever('sanatorium.githelper.' . $dir, function () use ($dir)
            {
                return $this->getRepoInformation($dir, false);
            });

            $repo['changed_files'] = $this->getChangedFilesCountFromDir($dir);

            return $repo;
        }

        $basename = basename($dir);
        $changed_files = $this->getChangedFilesCountFromDir($dir);
        $last_tag = $this->getLastTagFromDir($dir);
        $has_readme = file_exists($this->getReadmePath($dir));
        $composer = $this->getComposerInfo($dir);

        $repo = [
            'dir'           => $dir,
            'basename'      => $basename,
            'changed_files' => $changed_files,
            'last_tag'      => $last_tag,
            'has_readme'    => $has_readme,
            'type'          => (isset($composer['type']) ? $composer['type'] : 'unknown'),
            'name'          => (isset($composer['name']) ? $composer['name'] : 'unknown'),
            'authors'       => (isset($composer['authors']) ? $composer['authors'] : 'unknown'),
            'description'   => (isset($composer['description']) ? $composer['description'] : 'no description available'),
            'keywords'      => (isset($composer['keywords']) ? $composer['keywords'] : 'unknown'),
            'langs'         => [
                'en' => file_exists($this->getLangPath($dir, 'en')),
                'cs' => file_exists($this->getLangPath($dir, 'cs')),
            ],
        ];

        return $repo;
    }

    public function flushCache($dir = null)
    {
        Cache::forget('sanatorium.githelper.' . $dir);

        $this->getRepoInformation($dir);
    }

    /**
     * Refresh information about repository
     * @param null $dir
     * @return mixed
     */
    public function refresh($dir = null)
    {
        $this->flushCache($dir);

        $this->alerts->success(trans('sanatorium/githelper::common.messages.refresh.success'));

        return redirect()->back();
    }

    /**
     * Remove the last used tag on repository
     * @param $dir
     */
    public function untag($dir = null)
    {
        $repo = $this->getRepoInformation($dir, false);

        exec('git -C "' . $dir . '" tag -d ' . $repo['last_tag']);

        $this->flushCache($dir);

        $this->alerts->success(trans('sanatorium/githelper::common.messages.untag.success', ['tag' => $repo['last_tag']]));

        return redirect()->back();
    }

    public function getChangedFilesCountFromDir($dir)
    {
        return (int) exec('git -C "' . $dir . '" status | grep \'modified:\' | wc -l');
    }

    public function getLastTagFromDir($dir)
    {
        return exec('git -C "' . $dir . '" describe --tags');
    }

    public function getReadmePath($dir = null)
    {
        $readmeFilename = 'README.md';

        return $dir . '/' . $readmeFilename;
    }

    public function getComposerPath($dir = null)
    {
        $composerFilename = 'composer.json';

        return $dir . '/' . $composerFilename;
    }

    public function getLangPath($dir = null, $lang = null)
    {
        $langFolder = 'lang/' . $lang;

        return $dir . '/' . $langFolder;
    }

    /**
     * Create readme file if does not exist
     */
    public function readme()
    {
        $dir = request()->get('dir');

        $readmePath = $this->getReadmePath($dir);

        if ( file_exists($readmePath) )
        {
            $this->alerts->error(trans('sanatorium/githelper::common.messages.readme.exists'));

            return redirect()->back();
        }

        $readmeContents = $this->getReadmeContents($dir);

        file_put_contents($readmePath, $readmeContents);

        $this->refresh($dir);

        $this->alerts->success(trans('sanatorium/githelper::common.messages.readme.success'));

        return redirect()->back();
    }

    /**
     * @param null  $dir
     * @param array $keywords
     * @return bool
     */
    public function keywords($dir = null, $keywords = ["laravel", "cartalyst", "platform", "extension", "madeinsane"])
    {
        if ( request()->has('dir') )
        {
            $dir = request()->get('dir');
        }

        if ( !$dir ) {
            $dirs = $this->getRepositories(false);

            foreach( $dirs as $dir ) {
                $this->keywords($dir);
            }

            return redirect()->back();
        }

        $composerPath = $this->getComposerPath($dir);

        if ( !file_exists($composerPath) )
        {
            // Package has no composer
            return false;
        }

        $composer = json_decode( file_get_contents($composerPath), true );

        if ( !isset($composer['keywords']) ) {
            $composer['keywords'] = $keywords;
        }

        file_put_contents($composerPath, json_encode($composer, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

        return true;
    }

    public function bulkPatch()
    {
        $dirs = $this->getRepositories(false);

        foreach( $dirs as $dir ) {
            $this->tagpush('patch', $dir);
        }
    }

    public function align()
    {
        if ( ! request()->has('align') )
        {
            $this->alerts->error(trans('sanatorium/githelper::common.messages.align.noversion'));

            return redirect()->back();
        }

        $repos = $this->getRepositories( $full = false );
        $new_tag = request()->get('align');
        $message = request()->get('message');

        foreach( $repos as $dir )
        {
            $this->tagpush($type = 'version', $dir, $new_tag, $message);
        }

        $this->alerts->success(trans('sanatorium/githelper::common.messages.align.success', compact('message', 'new_tag')));

        return redirect()->back();
    }

    /**
     * Returns parsed composer.json information.
     *
     * @param null $dir
     * @return array
     */
    public function getComposerInfo($dir = null)
    {
        $composerJsonPath = $dir . '/composer.json';

        if ( file_exists($composerJsonPath) )
        {
            $info = json_decode(file_get_contents($composerJsonPath), true);
        }

        if ( isset($info) )
        {
            return $info;
        }

        return [];
    }

    /**
     * Sets new version in extension.php file
     * of selected extension, replacing the
     * current version of the package.
     *
     * @param      $dir
     * @param null $new_version
     * @return bool
     */
    public function updateExtensionVersion($dir, $new_version = null)
    {

        $extensionFilePath = $dir . '/extension.php';

        if ( $new_version && file_exists($extensionFilePath) ) {

            $currentContents = file_get_contents($extensionFilePath);

            $newFileContents = preg_replace("/'version' => '(.*)',/", "'version' => '".$new_version."',", $currentContents, 1);

            file_put_contents($extensionFilePath, $newFileContents);

            return true;

        }

        return false;

    }

    /**
     * Get default README.md file contents.
     *
     * @param null $dir
     * @return string
     */
    public function getReadmeContents($dir = null)
    {
        $info = $this->getComposerInfo($dir);

        return  str_replace(
            [
                '{{name}}',
                '{{description}}'
            ],
            [
                $info['name'],
                $info['description']
            ], self::getReadmeTemplate());

    }

    public static function getReadmeTemplate()
    {
        return "# {{name}}

{{description}}

## Contents

1. [Documentation](#documentation)
2. [Changelog](#changelog)
3. [Support](#support)
4. [Hooks](#hooks)

## Documentation

No documentation available.

## Changelog

Changelog not available.

## Support

Support not available.

## Hooks

List of currently used hooks:

    'sample' => '{{name}}::hooks.sample'";
    }

}
